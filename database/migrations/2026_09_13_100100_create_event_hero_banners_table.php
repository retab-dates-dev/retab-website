<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Homepage hero banners owned by a store event.
 *
 * 🔑 They belong to an EVENT rather than standing alone so they inherit its
 * window: a campaign's banners leave the homepage with the campaign, with nothing
 * to remember to switch off. Each banner may narrow that window further
 * (`starts_at`/`ends_at`, both optional) but can never outlive its event.
 *
 * While any banner is live the hero shows ONLY banners; with none, the four
 * copy slides return (see components/store/hero.tsx for why the two never mix).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_hero_banners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_event_id')->constrained()->cascadeOnDelete();

            // Desktop art (2:1) and the optional phone art (4:5).
            $table->string('image');
            $table->string('image_mobile')->nullable();

            // What the banner opens. Null → the event's own catalogue page.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // The art carries its own headline, so this is what a screen reader
            // hears instead. Null → the linked offer's (or the event's) name.
            $table->string('alt_ar')->nullable();
            $table->string('alt_en')->nullable();

            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_hero_banners');
    }
};
