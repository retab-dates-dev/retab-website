<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Takes time-boxed products off the storefront once their `available_until` has
 * passed. Scheduled every minute (routes/console.php).
 *
 * 🔑 It flips `is_active` rather than every storefront query learning to check a
 * date. `is_active` is already THE buyability gate — cart, checkout, catalogue,
 * search index, sitemap, navbar — so one write here keeps all of them right, and
 * the model events it fires bust the cached search index for free. A read-time
 * date check would have to be added to each of those and kept in step forever.
 *
 * Each product is saved individually (not a mass update) precisely so those model
 * events fire. The volume is a campaign's handful of offers, once.
 */
class HideExpiredProducts extends Command
{
    protected $signature = 'catalog:hide-expired';

    protected $description = 'Hide live products whose available-until time has passed.';

    public function handle(): int
    {
        $expired = Product::where('is_active', true)
            ->whereNotNull('available_until')
            ->where('available_until', '<=', now())
            ->get();

        foreach ($expired as $product) {
            $product->is_active = false;
            $product->save();
        }

        if ($expired->isNotEmpty()) {
            Log::info('Hid time-boxed products past their available-until', ['skus' => $expired->pluck('sku')->all()]);
        }

        $this->info("Hidden: {$expired->count()}");

        return self::SUCCESS;
    }
}
