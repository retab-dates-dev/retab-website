<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `products.available_until` — the moment a time-boxed product (a store-event
 * offer such as the National Day bundles) takes itself off the storefront.
 *
 * Deliberately distinct from `sale_ends_at`: that ends a DISCOUNT and the product
 * stays on sale at full price, whereas this ends the PRODUCT. A campaign bundle
 * has no "full price" life after its campaign.
 *
 * ⚠️ `dateTime`, not `timestamp`: MariaDB gives a TIMESTAMP column implicit
 * defaults that strict mode then rejects (the store-events migration hit it).
 * Enforced by Product's saving guard plus the every-minute `catalog:hide-expired`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dateTime('available_until')->nullable()->after('is_coming_soon');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('available_until');
        });
    }
};
