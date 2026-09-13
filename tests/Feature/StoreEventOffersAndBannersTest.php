<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\EventHeroBanner;
use App\Models\Product;
use App\Models\StoreEvent;
use App\Models\User;
use App\Support\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A store event as a whole campaign: offers created on the spot as time-boxed
 * products, the product-level "available until" that takes them off the store, and
 * the event's own homepage hero banners.
 */
class StoreEventOffersAndBannersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Media::disk());
    }

    private function admin(): User
    {
        return User::forceCreate([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@test.local',
            'password' => bcrypt('x'),
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * A live product WITH an image — without one, the publish guard would hide it
     * on its next save and every visibility assertion here would pass for the
     * wrong reason.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function product(array $overrides = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'dates'], ['name_ar' => 'تمور', 'name_en' => 'Dates', 'is_active' => true]);

        $product = Product::create(array_merge([
            'category_id' => $category->id,
            'name_ar' => 'منتج',
            'slug' => 'p-'.uniqid(),
            'price' => 96,
            'sku' => 'T-'.uniqid(),
            'stock' => 10,
            'is_active' => true,
        ], $overrides));
        $product->images()->create(['path' => "products/{$product->id}/a.webp", 'sort_order' => 1, 'is_primary' => true]);

        return $product;
    }

    /** @param array<string, mixed> $overrides */
    private function event(array $overrides = []): StoreEvent
    {
        return StoreEvent::create(array_merge([
            'name_ar' => 'اليوم الوطني السعودي',
            'name_en' => 'Saudi National Day',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addWeek(),
            'is_active' => true,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function banner(StoreEvent $event, array $overrides = []): EventHeroBanner
    {
        return $event->heroBanners()->create(array_merge(['image' => "events/{$event->id}/hero/a.webp"], $overrides));
    }

    // ------------------------------------------------------- available_until

    public function test_saving_a_live_product_past_its_available_until_hides_it(): void
    {
        $product = $this->product(['available_until' => now()->addHour()]);
        $this->assertTrue($product->fresh()->is_active);

        $product->update(['available_until' => now()->subMinute()]);
        $this->assertFalse($product->fresh()->is_active);

        // And it cannot be switched back on while the date is behind it — the
        // guard is on the model, so a list toggle or a change-log revert is caught too.
        $product->fresh()->update(['is_active' => true]);
        $this->assertFalse($product->fresh()->is_active);
    }

    public function test_hide_expired_takes_only_the_products_whose_time_has_come(): void
    {
        $expired = $this->product(['available_until' => now()->addHour()]);
        $future = $this->product(['available_until' => now()->addDay()]);
        $open = $this->product();

        // Time passes without a save: a raw update bypasses the saving guard, which
        // is exactly the gap the scheduled command exists to close.
        Product::whereKey($expired->id)->update(['available_until' => now()->subMinute()]);

        $this->artisan('catalog:hide-expired')->assertSuccessful();

        $this->assertFalse($expired->fresh()->is_active);
        $this->assertTrue($future->fresh()->is_active);
        $this->assertTrue($open->fresh()->is_active);
    }

    // --------------------------------------------------------- created offers

    public function test_an_offer_created_in_an_event_is_a_time_boxed_product_in_special_offers(): void
    {
        $event = $this->event();
        $this->product(['sku' => 'RTB-0091']);

        $this->actingAs($this->admin())
            ->post("/admin/store-events/{$event->id}/offers/new", [
                'name_ar' => 'عرض البكج',
                'name_en' => 'Package Offer',
                'description_ar' => 'يضم العرض خلاص وقهوة.',
                'price' => 96,
                'stock' => 100,
                'image' => UploadedFile::fake()->image('offer.png', 600, 600),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $offer = Product::where('name_ar', 'عرض البكج')->with('images', 'category')->firstOrFail();

        $this->assertSame('special-offers', $offer->category->slug);
        $this->assertSame($event->fresh()->ends_at->toDateTimeString(), $offer->available_until->toDateTimeString());
        $this->assertTrue($offer->is_active);
        $this->assertSame(100, $offer->stock);
        $this->assertSame('RTB-0092', $offer->sku, 'The next free RTB code, not a random one.');
        $this->assertCount(1, $offer->images);
        Storage::disk(Media::disk())->assertExists($offer->images->first()->path);
        $this->assertTrue($event->products()->whereKey($offer->id)->exists());
    }

    public function test_creating_an_offer_needs_the_product_create_right_as_well(): void
    {
        $event = $this->event();
        $editor = User::forceCreate([
            'name' => 'Editor',
            'email' => 'editor-'.uniqid().'@test.local',
            'password' => bcrypt('x'),
            'role' => 'editor',
            'email_verified_at' => now(),
            // May run events, may NOT create products: managing a campaign must not
            // be a back door into the catalogue.
            'permissions' => [
                'store_events' => ['view' => true, 'manage' => true],
                'products' => ['view' => true, 'create' => false],
            ],
        ]);

        $this->actingAs($editor)
            ->post("/admin/store-events/{$event->id}/offers/new", [
                'name_ar' => 'عرض',
                'price' => 96,
                'stock' => 1,
                'image' => UploadedFile::fake()->image('offer.png', 100, 100),
            ])
            ->assertForbidden();

        $this->assertFalse(Product::where('name_ar', 'عرض')->exists());
    }

    public function test_moving_the_event_end_carries_its_own_offers_but_not_hand_edited_ones(): void
    {
        $event = $this->event(['ends_at' => now()->addWeek()->startOfMinute()]);
        $end = $event->fresh()->ends_at;

        $born = $this->product(['available_until' => $end]);
        $handEdited = $this->product(['available_until' => $end->copy()->addDay()]);
        $catalogue = $this->product();
        $event->products()->attach([$born->id, $handEdited->id, $catalogue->id]);

        $newEnd = $end->copy()->addWeek();

        $this->actingAs($this->admin())
            ->put("/admin/store-events/{$event->id}", [
                'name_ar' => $event->name_ar,
                'starts_at' => $event->starts_at->toDateTimeString(),
                'ends_at' => $newEnd->toDateTimeString(),
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertSame($newEnd->toDateTimeString(), $born->fresh()->available_until->toDateTimeString());
        $this->assertSame($end->copy()->addDay()->toDateTimeString(), $handEdited->fresh()->available_until->toDateTimeString());
        $this->assertNull($catalogue->fresh()->available_until);
    }

    // ------------------------------------------------------------ hero banners

    public function test_the_homepage_ships_a_live_banner_linking_to_its_offer(): void
    {
        $event = $this->event();
        $offer = $this->product(['name_en' => null]);
        $event->products()->attach($offer->id);

        $this->banner($event, ['product_id' => $offer->id, 'image_mobile' => "events/{$event->id}/hero/m.webp", 'alt_ar' => 'بانر البكج']);
        $this->banner($event, ['sort_order' => 1]);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('heroBanners', 2)
            ->where('heroBanners.0.href', "/products/{$offer->slug}")
            ->where('heroBanners.0.alt_ar', 'بانر البكج')
            // No English alt and no English product name → the event's name.
            ->where('heroBanners.0.alt_en', 'Saudi National Day')
            // A banner linked to no offer opens the event's own catalogue page.
            ->where('heroBanners.1.href', "/shop?event={$event->id}")
            ->where('heroBanners.1.image_mobile', null));
    }

    public function test_a_banner_stays_off_the_homepage_whenever_it_should(): void
    {
        $scenarios = [
            'event paused' => fn () => $this->banner($this->event(['is_active' => false])),
            'event over' => fn () => $this->banner($this->event(['starts_at' => now()->subWeek(), 'ends_at' => now()->subMinute()])),
            'banner off' => fn () => $this->banner($this->event(), ['is_active' => false]),
            'own window not started' => fn () => $this->banner($this->event(), ['starts_at' => now()->addDay()]),
            'own window over' => fn () => $this->banner($this->event(), ['ends_at' => now()->subMinute()]),
            'offer hidden' => fn () => $this->banner($this->event(), ['product_id' => $this->product(['is_active' => false])->id]),
            'offer deleted' => function () {
                $product = $this->product();
                $product->delete();

                return $this->banner($this->event(), ['product_id' => $product->id]);
            },
        ];

        // Control first, so the loop below cannot pass vacuously.
        $this->banner($this->event());
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('heroBanners', 1));

        foreach ($scenarios as $label => $setup) {
            EventHeroBanner::query()->delete();
            StoreEvent::query()->delete();
            $setup();

            $count = count($this->get('/')->viewData('page')['props']['heroBanners']);
            $this->assertSame(0, $count, "Banner shown although: {$label}");
        }
    }

    public function test_a_banner_can_be_uploaded_and_only_link_to_its_own_events_offers(): void
    {
        $event = $this->event();
        $other = $this->event(['name_ar' => 'عروض أخرى']);
        $mine = $this->product();
        $theirs = $this->product();
        $event->products()->attach($mine->id);
        $other->products()->attach($theirs->id);

        $this->actingAs($this->admin());

        $this->post("/admin/store-events/{$event->id}/banners", [
            'image' => UploadedFile::fake()->image('d.png', 400, 200),
            'product_id' => $theirs->id,
        ])->assertSessionHasErrors('product_id');

        $this->post("/admin/store-events/{$event->id}/banners", [
            'image' => UploadedFile::fake()->image('d.png', 400, 200),
            'image_mobile' => UploadedFile::fake()->image('m.png', 160, 200),
            'product_id' => $mine->id,
        ])->assertSessionHasNoErrors();

        $banner = $event->heroBanners()->firstOrFail();
        $this->assertSame($mine->id, $banner->product_id);
        Storage::disk(Media::disk())->assertExists($banner->image);
        Storage::disk(Media::disk())->assertExists($banner->image_mobile);
    }

    public function test_a_banner_is_only_reachable_through_its_own_event(): void
    {
        $event = $this->event();
        $foreign = $this->banner($this->event(['name_ar' => 'أخرى']));

        $this->actingAs($this->admin())
            ->patch("/admin/store-events/{$event->id}/banners/{$foreign->id}", ['is_active' => false])
            ->assertNotFound();

        $this->assertTrue($foreign->fresh()->is_active);
    }

    // ----------------------------------------------------- National Day setup

    public function test_the_national_day_setup_builds_the_campaign_once(): void
    {
        $this->artisan('events:national-day-2026')->assertSuccessful();
        // Idempotent: a second run must create — and upload — nothing.
        $this->artisan('events:national-day-2026')->assertSuccessful();

        $this->assertSame(1, StoreEvent::count());
        $event = StoreEvent::with('products', 'heroBanners')->firstOrFail();

        // 00:00 on 1 October in Riyadh is 21:00 UTC on 30 September.
        $this->assertSame('2026-09-30 21:00:00', $event->ends_at->toDateTimeString());
        $this->assertSame([96.0, 96.0, 96.0, 96.0, 196.0], $event->products->map(fn (Product $p) => (float) $p->price)->all());
        $this->assertCount(5, $event->heroBanners);

        foreach ($event->products as $product) {
            $this->assertSame(100, $product->stock);
            $this->assertSame('2026-09-30 21:00:00', $product->available_until->toDateTimeString());
            $this->assertSame('special-offers', $product->category->slug);
        }
        $this->assertSame(5, EventHeroBanner::whereNotNull('image_mobile')->count());
        // Every strip card got its clean products-only artwork.
        $this->assertSame(5, $event->products->filter(fn (Product $p) => $p->pivot->banner_image !== null)->count());
    }
}
