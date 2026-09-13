<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\StoreEvent;
use App\Support\ArabicSlug;
use App\Support\Media;
use App\Support\ProductCodes;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-off: sets up the Saudi National Day 2026 campaign exactly as the designer
 * delivered it — the event, its five bundle offers (own stock, own photo, gone at
 * 00:00 on 1 October Riyadh time) and their five homepage hero banners.
 *
 * Everything here could be built by hand on /admin/store-events; this command is
 * the same result in one step and, more importantly, reproducible on production.
 * Run it on the WEB service (it needs the R2 media disk):
 *
 *     railway ssh --service retab-website -- php artisan events:national-day-2026
 *
 * Idempotent: the event is matched by name + start, each offer by its slug, each
 * banner by the offer it links to. A second run creates nothing and — crucially —
 * uploads nothing, so it can never orphan a file in R2.
 *
 * Artwork lives in database/data/national-day-2026/: `banner-*.webp` (2:1 web cut,
 * 1920 wide), `banner-*-mobile.webp` (4:5 social cut), `product-*.webp` (the
 * social cut squared for the store's aspect-square product photos, dropping the
 * campaign tagline above the price badge) and `card-*.webp` (a 2:1 band of just
 * the products, for the homepage event strip, whose cards overlay their own text).
 */
class SeedNationalDay2026 extends Command
{
    protected $signature = 'events:national-day-2026';

    protected $description = 'Create the Saudi National Day 2026 event with its five offers and hero banners (idempotent).';

    private const TIMEZONE = 'Asia/Riyadh';

    private const STARTS = '2026-09-13 00:00:00';

    /** Midnight STARTING 1 October, so the last day on sale is 30 September (client decision). */
    private const ENDS = '2026-10-01 00:00:00';

    private const STOCK = 100;

    private const UNTIL_AR = 'العرض متاح حتى نهاية يوم ٣٠ سبتمبر ٢٠٢٦ أو حتى نفاد الكمية.';

    private const UNTIL_EN = 'Available until the end of 30 September 2026 or while stocks last.';

    private const CHOICE_AR = 'بعد إتمام الطلب سيتواصل معك فريقنا عبر واتساب لتأكيد اختيارك.';

    private const CHOICE_EN = 'After you order, our team will contact you on WhatsApp to confirm your choice.';

    /**
     * In display order. Prices are the ones printed on the artwork: 96 for four
     * offers (the 96th National Day), 196 for the family bundle.
     *
     * @var array<string, array{ar:string, en:string, price:int, contents_ar:string, contents_en:string, choice:bool, alt_ar:string, alt_en:string}>
     */
    private const OFFERS = [
        'package' => [
            'ar' => 'عرض البكج لليوم الوطني',
            'en' => 'National Day Package Offer',
            'price' => 96,
            'contents_ar' => 'يضم العرض: ٢٫٥ كيلو خلاص درجة أولى، وقهوة الشيوخ، وطحينية، وبوكس معمول، وشابورة.',
            'contents_en' => 'The offer includes 2.5 kg of first-grade Khalas dates, Al Shuyoukh coffee, tahini, a maamoul box and rusks.',
            'choice' => false,
            'alt_ar' => 'عرض البكج لليوم الوطني: ٢٫٥ كيلو خلاص درجة أولى، قهوة الشيوخ، طحينية، بوكس معمول وشابورة',
            'alt_en' => 'National Day package offer: 2.5 kg first-grade Khalas, Al Shuyoukh coffee, tahini, a maamoul box and rusks',
        ],
        'boxes' => [
            'ar' => 'عرض البوكسات لليوم الوطني',
            'en' => 'National Day Boxes Offer',
            'price' => 96,
            'contents_ar' => 'يضم العرض: بوكس تمر محشي بالكريمة، وبوكس آخر من اختيارك: صقعي محشي باللوز أو سكري محشي باللوز.',
            'contents_en' => 'The offer includes a box of cream-stuffed dates plus a second box of your choice: almond-stuffed Sagai or almond-stuffed Sukkari.',
            'choice' => true,
            'alt_ar' => 'عرض البوكسات لليوم الوطني: بوكس محشي بالكريمة وبوكس من اختيارك، صقعي محشي لوز أو سكري محشي لوز',
            'alt_en' => 'National Day boxes offer: a cream-stuffed dates box plus a box of your choice, almond-stuffed Sagai or Sukkari',
        ],
        'khalas' => [
            'ar' => 'عرض الخلاص لليوم الوطني',
            'en' => 'National Day Khalas Offer',
            'price' => 96,
            'contents_ar' => 'يضم العرض: ٦ كيلو خلاص من اختيارك: خلاص درجة أولى، أو خلاص بالزعفران، أو خلاص الشمر.',
            'contents_en' => 'The offer includes 6 kg of Khalas dates of your choice: first-grade, saffron or fennel Khalas.',
            'choice' => true,
            'alt_ar' => 'عرض الخلاص لليوم الوطني: ٦ كيلو خلاص درجة أولى أو خلاص بالزعفران أو خلاص الشمر',
            'alt_en' => 'National Day Khalas offer: 6 kg of first-grade, saffron or fennel Khalas',
        ],
        'diet' => [
            'ar' => 'عرض الدايت لليوم الوطني',
            'en' => 'National Day Diet Offer',
            'price' => 96,
            'contents_ar' => 'يضم العرض: طحين بر بلدي عضوي (حبة كاملة)، وقرانولا التمر، وبوكس معمول، وشابورة أصل الرشاقة، ودبس التمر، وقهوة الشيوخ.',
            'contents_en' => 'The offer includes organic wholegrain local wheat flour, date granola, a maamoul box, Al Rashaqa rusks, date molasses and Al Shuyoukh coffee.',
            'choice' => false,
            'alt_ar' => 'عرض الدايت لليوم الوطني: طحين بر بلدي عضوي، قرانولا التمر، بوكس معمول، شابورة أصل الرشاقة، دبس وقهوة الشيوخ',
            'alt_en' => 'National Day diet offer: organic wholegrain flour, date granola, a maamoul box, Al Rashaqa rusks, date molasses and Al Shuyoukh coffee',
        ],
        'family' => [
            'ar' => 'عرض العائلة لليوم الوطني',
            'en' => 'National Day Family Offer',
            'price' => 196,
            'contents_ar' => 'يضم العرض: ٣ كيلو سكري درجة أولى، و٦ كيلو خلاص درجة أولى، وطحينية.',
            'contents_en' => 'The offer includes 3 kg of first-grade Sukkari dates, 6 kg of first-grade Khalas dates and tahini.',
            'choice' => false,
            'alt_ar' => 'عرض العائلة لليوم الوطني: ٣ كيلو سكري درجة أولى، ٦ كيلو خلاص درجة أولى وطحينية',
            'alt_en' => 'National Day family offer: 3 kg first-grade Sukkari, 6 kg first-grade Khalas and tahini',
        ],
    ];

    public function handle(): int
    {
        $dir = database_path('data/national-day-2026');
        $starts = Carbon::parse(self::STARTS, self::TIMEZONE)->utc();
        $ends = Carbon::parse(self::ENDS, self::TIMEZONE)->utc();

        foreach (array_keys(self::OFFERS) as $key) {
            foreach (["product-{$key}.webp", "card-{$key}.webp", "banner-{$key}.webp", "banner-{$key}-mobile.webp"] as $file) {
                if (! is_file("{$dir}/{$file}")) {
                    $this->error("Missing artwork: {$dir}/{$file}");

                    return self::FAILURE;
                }
            }
        }

        $event = StoreEvent::firstOrCreate(
            ['name_ar' => 'اليوم الوطني السعودي', 'starts_at' => $starts],
            [
                'name_en' => 'Saudi National Day',
                'subtitle_ar' => 'عزّنا بطبعنا',
                'subtitle_en' => 'Proud by nature',
                'ends_at' => $ends,
                'accent_color' => StoreEvent::ACCENT_PRESETS['national_day'],
                'is_active' => true,
            ],
        );
        $category = StoreEvent::offersCategory();

        $products = 0;
        $banners = 0;
        $cards = 0;
        $i = 0;

        foreach (self::OFFERS as $key => $offer) {
            $slug = ArabicSlug::make($offer['ar']);
            $product = Product::withTrashed()->where('slug', $slug)->first();

            if (! $product) {
                $product = DB::transaction(function () use ($offer, $slug, $category, $ends, $dir, $key) {
                    $product = Product::create([
                        'category_id' => $category->id,
                        'name_ar' => $offer['ar'],
                        'name_en' => $offer['en'],
                        'slug' => $slug,
                        'sku' => ProductCodes::nextSku(),
                        'description_ar' => trim($offer['contents_ar'].' '.($offer['choice'] ? self::CHOICE_AR.' ' : '').self::UNTIL_AR),
                        'description_en' => trim($offer['contents_en'].' '.($offer['choice'] ? self::CHOICE_EN.' ' : '').self::UNTIL_EN),
                        'price' => $offer['price'],
                        'stock' => self::STOCK,
                        'is_active' => true,
                        'available_until' => $ends,
                    ]);

                    $product->images()->create([
                        'path' => Media::storeImageFromFile("{$dir}/product-{$key}.webp", "product-{$key}.webp", "products/{$product->id}"),
                        'alt' => $offer['en'],
                        'sort_order' => 1,
                        'is_primary' => true,
                    ]);

                    return $product;
                });
                $products++;
            }

            $event->products()->syncWithoutDetaching([$product->id => ['sort_order' => $i]]);

            // The homepage strip card's own 2:1 artwork: just the products, cut from
            // below the offer photo's printed text. Without it the card crops the
            // square photo through the middle of that text, and then lays its own
            // name and price over the remains.
            if ($event->products()->find($product->id)?->pivot->banner_image === null) {
                $event->products()->updateExistingPivot($product->id, [
                    'banner_image' => Media::storeImageFromFile("{$dir}/card-{$key}.webp", "card-{$key}.webp", "events/{$event->id}"),
                ]);
                $cards++;
            }

            if (! $event->heroBanners()->where('product_id', $product->id)->exists()) {
                $event->heroBanners()->create([
                    'image' => Media::storeImageFromFile("{$dir}/banner-{$key}.webp", "banner-{$key}.webp", "events/{$event->id}/hero"),
                    'image_mobile' => Media::storeImageFromFile("{$dir}/banner-{$key}-mobile.webp", "banner-{$key}-mobile.webp", "events/{$event->id}/hero"),
                    'product_id' => $product->id,
                    'alt_ar' => $offer['alt_ar'],
                    'alt_en' => $offer['alt_en'],
                    'sort_order' => $i,
                ]);
                $banners++;
            }

            $i++;
        }

        $this->info("Event #{$event->id} «{$event->name_ar}»: {$products} offers created, {$banners} hero banners created, {$cards} strip cards given artwork.");
        $this->line("Runs {$event->starts_at} → {$event->ends_at} UTC (00:00 Riyadh on 13 Sep → 00:00 Riyadh on 1 Oct).");

        return self::SUCCESS;
    }
}
