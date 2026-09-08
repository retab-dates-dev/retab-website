<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\StoreEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Named store events: the homepage strip, its window, and — the part that
 * actually needs guarding — the rule keeping an event product out of the ordinary
 * discounts surfaces at the same time.
 */
class StoreEventTest extends TestCase
{
    use RefreshDatabase;

    private function category(): Category
    {
        return Category::firstOrCreate(
            ['slug' => 'dates'],
            ['name_ar' => 'تمور', 'name_en' => 'Dates', 'is_active' => true],
        );
    }

    /** @param array<string, mixed> $overrides */
    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->category()->id,
            'name_ar' => 'سكري',
            'slug' => 'sukkari-'.uniqid(),
            'price' => 100,
            'sku' => 'SK-'.uniqid(),
            'stock' => 10,
            'is_active' => true,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function makeEvent(array $overrides = []): StoreEvent
    {
        return StoreEvent::create(array_merge([
            'name_ar' => 'اليوم الوطني السعودي',
            'name_en' => 'Saudi National Day',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addWeek(),
            'is_active' => true,
        ], $overrides));
    }

    /** A discounted product: on sale right now, so it qualifies for both surfaces. */
    private function makeDiscounted(): Product
    {
        return $this->makeProduct([
            'price' => 100,
            'sale_price' => 80,
            'sale_starts_at' => now()->subDay(),
            'sale_ends_at' => now()->addWeek(),
        ]);
    }

    // ---------------------------------------------------------------- the strip

    public function test_a_running_event_renders_with_its_offers(): void
    {
        $event = $this->makeEvent();
        $event->products()->attach($this->makeProduct(['name_ar' => 'عرض الشابورة'])->id);

        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('storeEvents', 1)
                ->where('storeEvents.0.name_ar', 'اليوم الوطني السعودي')
                ->where('storeEvents.0.name_en', 'Saudi National Day')
                ->has('storeEvents.0.offers', 1)
                ->where('storeEvents.0.offers.0.name_ar', 'عرض الشابورة'),
        );
    }

    public function test_a_scheduled_event_stays_hidden_until_it_starts(): void
    {
        $event = $this->makeEvent(['starts_at' => now()->addDay(), 'ends_at' => now()->addWeek()]);
        $event->products()->attach($this->makeProduct()->id);

        $this->assertSame('scheduled', $event->state());
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('storeEvents', 0));
    }

    public function test_an_expired_event_drops_off_the_homepage(): void
    {
        $event = $this->makeEvent(['starts_at' => now()->subWeeks(2), 'ends_at' => now()->subDay()]);
        $event->products()->attach($this->makeProduct()->id);

        $this->assertSame('ended', $event->state());
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('storeEvents', 0));
    }

    public function test_a_paused_event_is_hidden_even_inside_its_window(): void
    {
        $event = $this->makeEvent(['is_active' => false]);
        $event->products()->attach($this->makeProduct()->id);

        $this->assertSame('paused', $event->state());
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('storeEvents', 0));
    }

    /**
     * A live event whose products have all since been hidden must render NOTHING,
     * not a titled section with an empty carousel under it. Realistic: a product
     * can be deactivated, or fail the publish guard, while still attached.
     */
    public function test_an_event_whose_offers_are_all_hidden_renders_nothing(): void
    {
        $event = $this->makeEvent();
        $event->products()->attach($this->makeProduct(['is_active' => false])->id);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('storeEvents', 0));
    }

    public function test_offers_come_back_in_the_arranged_order(): void
    {
        $event = $this->makeEvent();
        $first = $this->makeProduct(['name_ar' => 'الأول']);
        $second = $this->makeProduct(['name_ar' => 'الثاني']);

        // Attached in reverse, so passing would be impossible on insertion order.
        $event->products()->attach($second->id, ['sort_order' => 1]);
        $event->products()->attach($first->id, ['sort_order' => 0]);

        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('storeEvents.0.offers.0.name_ar', 'الأول')
                ->where('storeEvents.0.offers.1.name_ar', 'الثاني'),
        );
    }

    /** The commonest badge needs nothing typed: it is derived from the sale price. */
    public function test_the_discount_badge_is_derived_but_a_typed_badge_wins(): void
    {
        $event = $this->makeEvent();
        $derived = $this->makeDiscounted();
        $typed = $this->makeDiscounted();

        $event->products()->attach($derived->id, ['sort_order' => 0]);
        $event->products()->attach($typed->id, ['sort_order' => 1, 'badge_ar' => 'كرتونين + الثالث مجاناً']);

        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('storeEvents.0.offers.0.discount_percent', 20)
                ->where('storeEvents.0.offers.0.badge_ar', null)
                ->where('storeEvents.0.offers.1.badge_ar', 'كرتونين + الثالث مجاناً'),
        );
    }

    // ------------------------------------------------- the split (the real guard)

    /**
     * 🔴 The rule the whole feature rests on. A discounted product inside a running
     * event is shown in that event's own section; without the exclusion it would
     * ALSO appear in the العروض strip lower down the same page.
     */
    public function test_an_event_product_is_excluded_from_the_homepage_discounts_strip(): void
    {
        $inEvent = $this->makeDiscounted();
        $ordinary = $this->makeDiscounted();
        $this->makeEvent()->products()->attach($inEvent->id);

        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('offers', 1)
                ->where('offers.0.id', $ordinary->id)
                ->has('storeEvents.0.offers', 1)
                ->where('storeEvents.0.offers.0.id', $inEvent->id),
        );
    }

    /** The strip links here, so the same exclusion has to hold or the two disagree. */
    public function test_an_event_product_is_excluded_from_the_on_sale_catalogue_filter(): void
    {
        $inEvent = $this->makeDiscounted();
        $ordinary = $this->makeDiscounted();
        $this->makeEvent()->products()->attach($inEvent->id);

        $this->get('/shop?on_sale=1')->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('products.data', 1)
                ->where('products.data.0.id', $ordinary->id),
        );
    }

    /**
     * The call site easiest to forget: `hasOffers` drives the Offers nav item, the
     * mobile drawer entry and the cart's empty-state link. Miss it and the nav
     * offers a link to a page with nothing on it.
     */
    public function test_has_offers_is_false_when_every_discount_sits_inside_an_event(): void
    {
        $product = $this->makeDiscounted();
        $this->makeEvent()->products()->attach($product->id);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hasOffers', false));
    }

    /**
     * The exclusion is scoped to the on-sale surfaces ONLY. An event product is an
     * ordinary product everywhere else — it keeps its category and stays browsable,
     * which is what stops a campaign from emptying a category for its duration.
     */
    public function test_an_event_product_still_appears_in_ordinary_browsing(): void
    {
        $product = $this->makeDiscounted();
        $this->makeEvent()->products()->attach($product->id);

        $this->get('/shop')->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('products.data', 1)
                ->where('products.data.0.id', $product->id),
        );
        $this->get('/products/'.$product->slug)->assertOk();
    }

    /** Once the event is over, its products rejoin the ordinary discounts strip. */
    public function test_the_exclusion_lifts_when_the_event_ends(): void
    {
        $product = $this->makeDiscounted();
        $event = $this->makeEvent();
        $event->products()->attach($product->id);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('offers', 0));

        $event->update(['ends_at' => now()->subMinute()]);

        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('offers', 1)->where('hasOffers', true),
        );
    }

    // --------------------------------------------------------------------- nav

    /**
     * 🔴 The reported bug. `navCategories` had no has-products filter, so the
     * العروض الخاصة bucket seeded by the store-events migration rendered a navbar
     * item leading straight to "No products in this category". The catalogue's own
     * filter chips have always excluded empty categories; the navbar never did.
     */
    public function test_the_navbar_hides_a_category_with_no_visible_products(): void
    {
        // Seeded by the migration, deliberately empty until a bundle is created.
        $this->assertNotNull(Category::where('slug', 'special-offers')->first());
        $this->makeProduct();

        $this->get('/')->assertOk()->assertInertia(function (Assert $page) {
            $page->has('navCategories');
            $slugs = collect($page->toArray()['props']['navCategories'])->pluck('slug');
            $this->assertNotContains('special-offers', $slugs, 'an empty category must not reach the navbar');
            $this->assertContains('dates', $slugs, 'a category with products still must');
        });
    }

    /** Give it a product and it earns its place, without any code change. */
    public function test_the_navbar_shows_that_category_once_it_holds_a_product(): void
    {
        $bundle = Category::where('slug', 'special-offers')->first();
        $this->makeProduct(['category_id' => $bundle->id, 'name_ar' => 'عرض مجمّع']);

        $this->get('/')->assertOk()->assertInertia(function (Assert $page) {
            $slugs = collect($page->toArray()['props']['navCategories'])->pluck('slug');
            $this->assertContains('special-offers', $slugs);
        });
    }

    public function test_the_navbar_lists_a_running_event_under_its_own_name(): void
    {
        $event = $this->makeEvent();
        $event->products()->attach($this->makeProduct()->id);

        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('navEvents', 1)
                ->where('navEvents.0.id', $event->id)
                // The NAME, not a fixed "Special Offers" label — that is the whole ask.
                ->where('navEvents.0.name_ar', 'اليوم الوطني السعودي')
                ->where('navEvents.0.name_en', 'Saudi National Day'),
        );
    }

    public function test_the_navbar_lists_no_event_when_none_is_running(): void
    {
        $this->makeEvent(['starts_at' => now()->addWeek(), 'ends_at' => now()->addWeeks(2)])
            ->products()->attach($this->makeProduct()->id);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('navEvents', 0));
    }

    /** Same discipline as `hasOffers`: never link to a destination with nothing on it. */
    public function test_the_navbar_hides_an_event_whose_offers_are_all_hidden(): void
    {
        $this->makeEvent()->products()->attach($this->makeProduct(['is_active' => false])->id);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('navEvents', 0));
    }

    // ---------------------------------------------------- browsing one campaign

    public function test_the_event_filter_shows_only_that_events_offers(): void
    {
        $event = $this->makeEvent();
        $inEvent = $this->makeProduct(['name_ar' => 'داخل الحملة']);
        $this->makeProduct(['name_ar' => 'خارج الحملة']);
        $event->products()->attach($inEvent->id);

        $this->get("/shop?event={$event->id}")->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('products.data', 1)
                ->where('products.data.0.id', $inEvent->id)
                // The heading is the campaign's name, so the shopper knows what
                // they are looking at rather than an unlabelled filtered list.
                ->where('activeEvent.name_ar', 'اليوم الوطني السعودي'),
        );
    }

    /**
     * A stale link (a shared WhatsApp message, a bookmark) must degrade to the
     * plain catalogue rather than resurrect a finished campaign's line-up.
     */
    public function test_a_link_to_a_finished_event_falls_back_to_the_whole_catalogue(): void
    {
        $event = $this->makeEvent(['starts_at' => now()->subWeeks(2), 'ends_at' => now()->subDay()]);
        $event->products()->attach($this->makeProduct()->id);
        $this->makeProduct(['name_ar' => 'منتج آخر']);

        $this->get("/shop?event={$event->id}")->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('activeEvent', null)
                ->has('products.data', 2),
        );
    }

    // ------------------------------------------------------------------- admin

    private function admin(): User
    {
        return User::forceCreate([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@retab.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    public function test_an_admin_can_create_an_event_and_attach_an_offer(): void
    {
        $this->actingAs($this->admin());
        $product = $this->makeProduct();

        $this->post('/admin/store-events', [
            'name_ar' => 'اليوم الوطني السعودي',
            'name_en' => 'Saudi National Day',
            'starts_at' => now()->subDay()->toDateTimeString(),
            'ends_at' => now()->addWeek()->toDateTimeString(),
            'accent_color' => '#006c35',
            'is_active' => true,
        ])->assertRedirect();

        $event = StoreEvent::firstOrFail();
        $this->assertSame('#006c35', $event->accent());

        $this->post("/admin/store-events/{$event->id}/offers", ['product_id' => $product->id])
            ->assertRedirect();

        $this->assertTrue($event->products()->whereKey($product->id)->exists());
    }

    /**
     * Renders the event page for real.
     *
     * ⚠️ Added after a `BadMethodCallException` reached the browser: the offer
     * payload called `Product::saleState()`, a method that does not exist (it is
     * `saleStatus()`). Seventeen tests were green because not one of them had ever
     * asked this controller to build an offer row — the admin screens were covered
     * only through their write endpoints.
     */
    public function test_the_event_page_renders_its_offers_and_the_product_pool(): void
    {
        $this->actingAs($this->admin());
        $event = $this->makeEvent();
        $attached = $this->makeDiscounted();
        $spare = $this->makeProduct(['name_ar' => 'غير مضاف']);
        $event->products()->attach($attached->id, ['badge_ar' => 'عرض']);

        $this->get("/admin/store-events/{$event->id}")->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('admin/store-events/show')
                ->has('event.offers', 1)
                ->where('event.offers.0.product_id', $attached->id)
                ->where('event.offers.0.badge_ar', 'عرض')
                ->where('event.offers.0.sale_state', 'active')
                ->where('event.offers.0.has_banner', false)
                // The pool is what is addable, so it must EXCLUDE what is already in.
                ->has('pool', 1)
                ->where('pool.0.id', $spare->id),
        );
    }

    /** A product with no discount must not be reported as having a sale state. */
    public function test_an_offer_without_a_discount_reports_no_sale_state(): void
    {
        $this->actingAs($this->admin());
        $event = $this->makeEvent();
        $event->products()->attach($this->makeProduct()->id);

        $this->get("/admin/store-events/{$event->id}")->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('event.offers.0.sale_state', null)
                ->where('event.offers.0.on_sale', false),
        );
    }

    public function test_the_event_list_renders(): void
    {
        $this->actingAs($this->admin());
        $this->makeEvent()->products()->attach($this->makeProduct()->id);

        $this->get('/admin/store-events')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('admin/store-events/index')
                ->has('events.data', 1)
                ->where('events.data.0.state', 'active')
                ->where('events.data.0.offer_count', 1)
                // Resolved server-side, so the page never has to know the fallback.
                ->where('events.data.0.accent', '#1b4e53')
                ->has('accentPresets'),
        );
    }

    public function test_an_event_rejects_an_end_before_its_start(): void
    {
        $this->actingAs($this->admin());

        $this->post('/admin/store-events', [
            'name_ar' => 'حدث',
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->toDateTimeString(),
            'is_active' => true,
        ])->assertSessionHasErrors('ends_at');
    }

    public function test_reordering_only_touches_products_already_in_the_event(): void
    {
        $this->actingAs($this->admin());
        $event = $this->makeEvent();
        $a = $this->makeProduct();
        $b = $this->makeProduct();
        $outsider = $this->makeProduct();
        $event->products()->attach([$a->id => ['sort_order' => 0], $b->id => ['sort_order' => 1]]);

        $this->post("/admin/store-events/{$event->id}/offers/reorder", [
            'product_ids' => [$b->id, $a->id, $outsider->id],
        ])->assertRedirect();

        $this->assertSame(0, (int) $event->products()->find($b->id)->pivot->sort_order);
        $this->assertSame(1, (int) $event->products()->find($a->id)->pivot->sort_order);
        // A stale page must not be able to ADD a product through the reorder route.
        $this->assertFalse($event->products()->whereKey($outsider->id)->exists());
    }

    /**
     * An offer endpoint reached through the wrong event must 404 rather than edit
     * another event's pivot row — which for the banner endpoints would also delete
     * artwork that is still in use.
     */
    public function test_an_offer_endpoint_rejects_a_product_from_another_event(): void
    {
        $this->actingAs($this->admin());
        $mine = $this->makeEvent();
        $theirs = $this->makeEvent(['name_ar' => 'حدث آخر']);
        $product = $this->makeProduct();
        $theirs->products()->attach($product->id);

        $this->patch("/admin/store-events/{$mine->id}/offers/{$product->id}", ['badge_ar' => 'x'])
            ->assertNotFound();
        $this->delete("/admin/store-events/{$mine->id}/offers/{$product->id}")
            ->assertNotFound();
    }

    public function test_an_editor_without_the_permission_cannot_manage_events(): void
    {
        $editor = User::forceCreate([
            'name' => 'Editor',
            'email' => 'editor-'.uniqid().'@retab.test',
            'password' => bcrypt('password'),
            'role' => 'editor',
            // The shipped default: may read the campaign, may not publish one.
            'permissions' => ['store_events' => ['view' => true, 'manage' => false]],
        ]);
        $this->actingAs($editor);

        $this->get('/admin/store-events')->assertOk();
        $this->post('/admin/store-events', [
            'name_ar' => 'حدث',
            'starts_at' => now()->toDateTimeString(),
            'ends_at' => now()->addDay()->toDateTimeString(),
            'is_active' => true,
        ])->assertForbidden();
    }
}
