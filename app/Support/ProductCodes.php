<?php

namespace App\Support;

use App\Models\Product;

/**
 * Identifiers for products created without an admin typing them — the offers
 * built inside a store event, and the campaign setup commands.
 */
class ProductCodes
{
    /**
     * The next free `RTB-####` code.
     *
     * withTrashed: a soft-deleted product still holds its SKU in the unique index,
     * so handing its number out again would fail the insert.
     */
    public static function nextSku(): string
    {
        $max = Product::withTrashed()
            ->where('sku', 'like', 'RTB-%')
            ->pluck('sku')
            ->map(fn (string $sku) => (int) substr($sku, 4))
            ->max() ?? 0;

        return 'RTB-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * An Arabic slug from the product's Arabic name, suffixed to stay unique.
     * Arabic because the store's slugs are (client decision, 2026-07-25).
     */
    public static function uniqueSlug(string $nameAr): string
    {
        $base = ArabicSlug::make($nameAr) ?: 'product-'.substr(md5($nameAr), 0, 6);
        $slug = $base;
        $i = 2;

        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
