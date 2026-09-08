<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store events — a NAMED, time-boxed campaign ("اليوم الوطني السعودي") that gets
 * its own homepage strip above Best Sellers, titled by the event itself.
 *
 * 🔑 Why this is a table and not a flag on `products`: an event carries a name, a
 * subtitle and a window, and the section heading is read from it. A boolean column
 * can hold none of those, and the heading would have to be hardcoded copy — which
 * is exactly what the client ruled out ("sometimes we want the event to be named").
 *
 * ⚠️ Deliberately SEPARATE from the ordinary discounts strip (`onSale()`). A
 * product attached to a running event is excluded from that strip, from
 * `/shop?on_sale=1` and from `hasOffers`, so the same product can never appear in
 * both places on one page. That rule lives in one scope,
 * `Product::scopeNotInRunningEvent()` — see it for the full reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_events', function (Blueprint $table) {
            $table->id();

            // Bilingual, AR-first (EN optional, falls back to AR) — the storefront
            // convention. The name IS the section heading, so it is required.
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('subtitle_ar', 255)->nullable();
            $table->string('subtitle_en', 255)->nullable();

            // Both bounds required, unlike products' optional sale window: an event
            // that never ends is not an event, and an open-ended one would sit on
            // the homepage forever with nobody noticing.
            //
            // ⚠️ `dateTime`, NOT `timestamp`. MariaDB gives the FIRST non-nullable
            // TIMESTAMP column in a table an implicit CURRENT_TIMESTAMP default and
            // every later one a '0000-00-00' default, which strict mode then
            // rejects outright: "Invalid default value for 'ends_at'". The products
            // table dodges this only because its sale window is nullable. DATETIME
            // has no such rule, and no 2038 ceiling either.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // One accent per event (National Day green, Ramadan, …). NULL means the
            // ordinary brand teal, so an event that wants no dressing costs nothing.
            // Stored as a hex string; the admin offers presets rather than a free
            // colour picker, which is a licence to pick a bad colour.
            $table->string('accent_color', 7)->nullable();

            // Kill switch independent of the dates — pull a live event without
            // having to rewrite its window and lose the real dates.
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // Covers scopeRunning(): active AND inside the window.
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('event_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Optional designed artwork for this offer's card. When empty the card
            // falls back to the product's own primary photo cropped to 16:9, so an
            // event is never blocked waiting on a designer.
            $table->string('banner_image')->nullable();

            // The gold pill on the card ("كرتونين + الثالث مجاناً"). A plain
            // percentage is DERIVED from the sale price instead, so this only has to
            // be filled for an offer the discount cannot describe on its own.
            $table->string('badge_ar', 60)->nullable();
            $table->string('badge_en', 60)->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // One row per product per event — attaching twice is a mistake, not a
            // second offer.
            $table->unique(['store_event_id', 'product_id']);
        });

        // Permanent home for bundle products created for an event (the "two types
        // of rusk" case). They stay browsable between events instead of vanishing
        // when the campaign ends.
        //
        // 🔑 Seeded HERE, not in a seeder: categories have no admin UI, and Railpack
        // runs `migrate --force` but never `db:seed`, so a fresh production deploy
        // would otherwise come up with nowhere to put a bundle. Guarded so a re-run
        // is a no-op.
        //
        // ⚠️ Deliberately NO image: the homepage category tiles query
        // `whereNotNull('image')`, and a tile for a category that is empty between
        // events would be a dead end.
        if (! DB::table('categories')->where('slug', 'special-offers')->exists()) {
            DB::table('categories')->insert([
                'name_ar' => 'العروض الخاصة',
                'name_en' => 'Special Offers',
                'slug' => 'special-offers',
                'sort_order' => 90,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_product');
        Schema::dropIfExists('store_events');
        DB::table('categories')->where('slug', 'special-offers')->delete();
    }
};
