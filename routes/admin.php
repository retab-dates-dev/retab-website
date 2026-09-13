<?php

use App\Http\Controllers\Admin\ChangeLogController;
use App\Http\Controllers\Admin\ClientReviewController;
use App\Http\Controllers\Admin\ContactMessageController;
use App\Http\Controllers\Admin\ContentPageController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DiscountController;
use App\Http\Controllers\Admin\GlobalSearchController;
use App\Http\Controllers\Admin\MarketingController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PreferenceController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImageController;
use App\Http\Controllers\Admin\ProductRequestController;
use App\Http\Controllers\Admin\ProductReviewController;
use App\Http\Controllers\Admin\ReturnController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\ShippingController;
use App\Http\Controllers\Admin\StockImportController;
use App\Http\Controllers\Admin\StoreEventController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

// Back-office (EN-first). Staff only — admin or editor.
Route::middleware(['auth', 'staff', 'admin.locale'])->prefix('admin')->name('admin.')->group(function () {
    // Bare /admin → dashboard. The `auth` middleware sends guests to /login first,
    // so an unauthenticated visitor lands on login and a signed-in staff member on
    // the dashboard (no more bare-/admin 404).
    Route::redirect('/', '/admin/dashboard');

    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('search', [GlobalSearchController::class, 'search'])->name('search');
    Route::put('preferences/table-widths', [PreferenceController::class, 'tableWidths'])->name('preferences.table-widths');

    // Notification bell + history (every staff member has their own copies — no
    // extra permission). `read-all` is declared before the `{notification}`
    // wildcard so the literal segment always wins.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('notifications/{notification}', [NotificationController::class, 'open'])->name('notifications.open');

    // Orders.
    Route::get('orders', [OrderController::class, 'index'])->middleware('permission:orders.view')->name('orders.index');
    Route::get('orders/export', [OrderController::class, 'export'])->middleware('permission:orders.export')->name('orders.export');
    Route::get('orders/{order:order_number}/detail', [OrderController::class, 'detail'])->middleware('permission:orders.view')->name('orders.detail');
    Route::get('orders/{order:order_number}', [OrderController::class, 'show'])->middleware('permission:orders.view')->name('orders.show');
    Route::middleware('permission:orders.manage')->group(function () {
        // GET, but gated on `manage` not `view`: it pushes the order to OTO and
        // burns a live rate lookup, and only someone who can ship needs it.
        Route::get('orders/{order:order_number}/shipping-quotes', [OrderController::class, 'shippingQuotes'])->name('orders.shipping-quotes');
        Route::post('orders/{order:order_number}/confirm', [OrderController::class, 'confirm'])->name('orders.confirm');
        Route::post('orders/{order:order_number}/unavailable', [OrderController::class, 'markUnavailable'])->name('orders.unavailable');
        Route::post('orders/{order:order_number}/ship', [OrderController::class, 'ship'])->name('orders.ship');
        // Recalls the parcel and returns the order to confirmed — distinct from
        // `orders.cancel`, which kills the order and refunds it.
        Route::post('orders/{order:order_number}/cancel-shipment', [OrderController::class, 'cancelShipment'])->name('orders.cancel-shipment');
        Route::post('orders/{order:order_number}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
        // Re-open a lapsed gateway hold and WhatsApp the customer a signed link to
        // finish paying — the recovery for an expired Tamara authorisation.
        Route::post('orders/{order:order_number}/payment-link', [OrderController::class, 'sendPaymentLink'])->name('orders.payment-link');
    });

    // Products.
    Route::get('products/export', [ProductController::class, 'export'])->middleware('permission:products.view')->name('products.export');
    Route::get('products', [ProductController::class, 'index'])->middleware('permission:products.view')->name('products.index');
    // Form helpers: an English name from the Arabic one, and a description from
    // the product's attributes.
    //
    // Gated on products.VIEW, not create/edit: it is reachable from both forms,
    // and `products.create` and `products.edit` are separately grantable — an
    // editor with one but not the other would 403 on a button they can see. It
    // leaks nothing either way, only transforming text the caller supplied.
    Route::post('products/suggest', [ProductController::class, 'suggest'])->middleware('permission:products.view')->name('products.suggest');
    Route::get('products/create', [ProductController::class, 'create'])->middleware('permission:products.create')->name('products.create');
    Route::post('products', [ProductController::class, 'store'])->middleware('permission:products.create')->name('products.store');
    Route::get('products/{product}/detail', [ProductController::class, 'detail'])->middleware('permission:products.edit')->name('products.detail');
    Route::get('products/{product}/edit', [ProductController::class, 'edit'])->middleware('permission:products.edit')->name('products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])->middleware('permission:products.edit')->name('products.update');
    Route::patch('products/{product}/toggle-active', [ProductController::class, 'toggleActive'])->middleware('permission:products.edit')->name('products.toggle-active');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('permission:products.delete')->name('products.destroy');

    // "I want this" demand signals for Coming-Soon products.
    Route::get('product-requests', [ProductRequestController::class, 'index'])->middleware('permission:product_requests.view')->name('product-requests.index');
    Route::post('product-requests/{productRequest}/handle', [ProductRequestController::class, 'markHandled'])->middleware('permission:product_requests.manage')->name('product-requests.handle');

    // Product reviews written by customers on the storefront. A TAKEDOWN path:
    // reviews publish on arrival (see ProductReviewController), so staff hide or
    // remove the occasional bad one rather than approving every good one.
    // ⚠️ Distinct from `client-reviews` below, which is the homepage testimonials.
    Route::get('product-reviews', [ProductReviewController::class, 'index'])->middleware('permission:product_reviews.view')->name('product-reviews.index');
    Route::patch('product-reviews/{review}/toggle', [ProductReviewController::class, 'toggleApproval'])->middleware('permission:product_reviews.manage')->name('product-reviews.toggle');
    Route::delete('product-reviews/{review}', [ProductReviewController::class, 'destroy'])->middleware('permission:product_reviews.manage')->name('product-reviews.destroy');

    // Product images (clean multipart POST, separate from the text form).
    Route::middleware('permission:products.edit')->group(function () {
        Route::post('products/{product}/images', [ProductImageController::class, 'store'])->name('products.images.store');
        Route::delete('products/{product}/images/{image}', [ProductImageController::class, 'destroy'])->name('products.images.destroy');
        Route::put('products/{product}/images/{image}/primary', [ProductImageController::class, 'setPrimary'])->name('products.images.primary');
    });

    // WhatsApp marketing — template registry + campaign sender.
    Route::get('marketing', [MarketingController::class, 'index'])->middleware('permission:marketing.view')->name('marketing.index');
    Route::middleware('permission:marketing.send')->group(function () {
        Route::post('marketing/templates', [MarketingController::class, 'storeTemplate'])->name('marketing.templates.store');
        Route::put('marketing/templates/{template}', [MarketingController::class, 'updateTemplate'])->name('marketing.templates.update');
        Route::post('marketing/campaigns', [MarketingController::class, 'storeCampaign'])->name('marketing.campaigns.store');
    });

    // Coupons — percentage / fixed / free-delivery, with date window + usage caps.
    Route::get('coupons', [CouponController::class, 'index'])->middleware('permission:coupons.view')->name('coupons.index');
    Route::post('coupons', [CouponController::class, 'store'])->middleware('permission:coupons.create')->name('coupons.store');
    Route::patch('coupons/{coupon}/toggle', [CouponController::class, 'toggle'])->middleware('permission:coupons.edit')->name('coupons.toggle');
    Route::put('coupons/{coupon}', [CouponController::class, 'update'])->middleware('permission:coupons.edit')->name('coupons.update');
    Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])->middleware('permission:coupons.delete')->name('coupons.destroy');

    // Discounts — bulk + CSV scheduled product sales.
    Route::get('discounts', [DiscountController::class, 'index'])->middleware('permission:discounts.view')->name('discounts.index');
    Route::middleware('permission:discounts.manage')->group(function () {
        Route::post('discounts/apply', [DiscountController::class, 'apply'])->name('discounts.apply');
        Route::post('discounts/free-shipping', [DiscountController::class, 'freeShipping'])->name('discounts.free-shipping');
        Route::post('discounts/review-reward', [DiscountController::class, 'reviewReward'])->name('discounts.review-reward');
        Route::post('discounts/import/preview', [DiscountController::class, 'previewImport'])->name('discounts.import.preview');
        Route::post('discounts/import/apply', [DiscountController::class, 'applyImport'])->name('discounts.import.apply');
        Route::post('discounts/clear', [DiscountController::class, 'clear'])->name('discounts.clear');
        Route::post('discounts/undo/{activityLog}', [DiscountController::class, 'undo'])->name('discounts.undo');
    });

    // Store events — named, time-boxed homepage campaigns ("اليوم الوطني السعودي").
    // Distinct from discounts: this decides what the campaign is CALLED and which
    // products it fronts; the price itself is still the product's own sale window.
    Route::get('store-events', [StoreEventController::class, 'index'])->middleware('permission:store_events.view')->name('store-events.index');
    Route::get('store-events/{store_event}', [StoreEventController::class, 'show'])->middleware('permission:store_events.view')->name('store-events.show');
    Route::middleware('permission:store_events.manage')->group(function () {
        Route::post('store-events', [StoreEventController::class, 'store'])->name('store-events.store');
        Route::put('store-events/{store_event}', [StoreEventController::class, 'update'])->name('store-events.update');
        Route::patch('store-events/{store_event}/toggle', [StoreEventController::class, 'toggle'])->name('store-events.toggle');
        Route::delete('store-events/{store_event}', [StoreEventController::class, 'destroy'])->name('store-events.destroy');

        // ⚠️ `offers/reorder` is declared BEFORE the `offers/{product}` wildcard.
        // It cannot actually collide here (reorder is POST, update is PATCH), but
        // the literal-before-wildcard order is the habit that stops the next
        // endpoint added under this prefix from being swallowed by it.
        Route::post('store-events/{store_event}/offers/reorder', [StoreEventController::class, 'reorderOffers'])->name('store-events.offers.reorder');
        // Creates a brand-new product, so it also needs the product-create right —
        // store_events.manage alone must not become a back door into the catalogue.
        Route::post('store-events/{store_event}/offers/new', [StoreEventController::class, 'createOffer'])
            ->middleware('permission:products.create')
            ->name('store-events.offers.create');
        Route::post('store-events/{store_event}/offers', [StoreEventController::class, 'attachOffer'])->name('store-events.offers.attach');
        Route::patch('store-events/{store_event}/offers/{product}', [StoreEventController::class, 'updateOffer'])->name('store-events.offers.update');
        Route::delete('store-events/{store_event}/offers/{product}', [StoreEventController::class, 'detachOffer'])->name('store-events.offers.detach');
        // Multipart, so POST — a PUT/PATCH body is not parsed by PHP and the file
        // would arrive empty (same reason product images have their own endpoint).
        Route::post('store-events/{store_event}/offers/{product}/banner', [StoreEventController::class, 'uploadOfferBanner'])->name('store-events.offers.banner');
        Route::delete('store-events/{store_event}/offers/{product}/banner', [StoreEventController::class, 'deleteOfferBanner'])->name('store-events.offers.banner.delete');

        // Hero banners. `reorder` before the `{banner}` wildcard, same habit as offers.
        Route::post('store-events/{store_event}/banners/reorder', [StoreEventController::class, 'reorderBanners'])->name('store-events.banners.reorder');
        Route::post('store-events/{store_event}/banners', [StoreEventController::class, 'storeBanner'])->name('store-events.banners.store');
        Route::patch('store-events/{store_event}/banners/{banner}', [StoreEventController::class, 'updateBanner'])->name('store-events.banners.update');
        Route::delete('store-events/{store_event}/banners/{banner}', [StoreEventController::class, 'destroyBanner'])->name('store-events.banners.destroy');
    });

    // Customer directory (read-only).
    Route::middleware('permission:customers.view')->group(function () {
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/export', [CustomerController::class, 'export'])->name('customers.export');
        Route::get('customers/{customer}/detail', [CustomerController::class, 'detail'])->name('customers.detail');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    });

    // Store settings + CMS pages.
    Route::get('settings', [SettingController::class, 'edit'])->middleware('permission:settings.view')->name('settings.edit');
    Route::put('settings', [SettingController::class, 'update'])->middleware('permission:settings.edit')->name('settings.update');
    // Admin-only safeguard: restore editable content to the project-handover defaults.
    // Gated by both the `admin` middleware and an in-controller isAdmin check.
    Route::post('settings/reset', [SettingController::class, 'reset'])->middleware('admin')->name('settings.reset');
    // Content pages are edit-only — the three baseline pages ship seeded; no adding/removing.
    Route::resource('content-pages', ContentPageController::class)
        ->only(['index', 'edit', 'update'])
        ->middleware('permission:content_pages.view')
        ->parameters(['content-pages' => 'contentPage']);

    // Inbox for the storefront Contact Us form.
    Route::get('contact-messages', [ContactMessageController::class, 'index'])->middleware('permission:contact_messages.view')->name('contact-messages.index');
    Route::post('contact-messages/{contactMessage}/handle', [ContactMessageController::class, 'markHandled'])->middleware('permission:contact_messages.manage')->name('contact-messages.handle');

    // Staff & access control.
    //
    // Reading the directory and resetting a colleague's password are permissions
    // an admin can hand to a trusted editor, because a staff account created on a
    // non-routable address has no reset-by-email and someone has to be able to let
    // them back in. Both are denied by default (Permission::DEFAULTS).
    //
    // 🔴 Everything that changes WHO HAS ACCESS — creating staff, removing them,
    // changing a role, editing the permission grid — stays `admin` only. An editor
    // able to edit the grid could grant themselves every section, so that one is
    // not a permission, it is a role.
    Route::get('users', [UserController::class, 'index'])->middleware('permission:staff.view')->name('users.index');
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])
        ->middleware(['permission:staff.reset_password', 'throttle:10,1,staff-password-reset'])
        ->name('users.reset-password');

    Route::middleware('admin')->group(function () {
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}/permissions', [UserController::class, 'updatePermissions'])->name('users.permissions');
        Route::put('users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // Curated client reviews (Google Maps testimonials pool) + bulk import.
    Route::get('client-reviews/import', [ClientReviewController::class, 'importForm'])->middleware('permission:reviews.manage')->name('client-reviews.import');
    Route::post('client-reviews/import', [ClientReviewController::class, 'importStore'])->middleware('permission:reviews.manage')->name('client-reviews.import.store');
    Route::get('client-reviews', [ClientReviewController::class, 'index'])->middleware('permission:reviews.view')->name('client-reviews.index');
    Route::resource('client-reviews', ClientReviewController::class)
        ->only(['create', 'store', 'edit', 'update', 'destroy'])
        ->middleware('permission:reviews.manage')
        ->parameters(['client-reviews' => 'clientReview']);

    // Returns review + resolution.
    Route::get('returns', [ReturnController::class, 'index'])->middleware('permission:returns.view')->name('returns.index');
    Route::get('returns/export', [ReturnController::class, 'export'])->middleware('permission:returns.view')->name('returns.export');
    Route::get('returns/{orderReturn}/detail', [ReturnController::class, 'detail'])->middleware('permission:returns.view')->name('returns.detail');
    Route::get('returns/{orderReturn}', [ReturnController::class, 'show'])->middleware('permission:returns.view')->name('returns.show');
    Route::middleware('permission:returns.resolve')->group(function () {
        Route::post('returns/{orderReturn}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
        Route::post('returns/{orderReturn}/reject', [ReturnController::class, 'reject'])->name('returns.reject');
        Route::post('returns/{orderReturn}/exchange', [ReturnController::class, 'exchange'])->name('returns.exchange');
        Route::post('returns/{orderReturn}/refund', [ReturnController::class, 'refund'])->name('returns.refund');
    });

    // Shipping carriers portal.
    //
    // 🔑 `manage` is what decides whether Retab will quote and ship with a carrier
    // (OtoGateway filters every rate lookup through it), so it is a real operational
    // switch rather than a display preference — hence a separate action from `view`,
    // which is only "see who to phone and what it costs".
    //
    // `refresh` is a POST despite reading nothing: it spends a live OTO API call, so
    // it must not be reachable by a link, a prefetch or a back button.
    Route::get('shipping', [ShippingController::class, 'index'])->middleware('permission:shipping.view')->name('shipping.index');
    Route::middleware('permission:shipping.manage')->group(function () {
        Route::post('shipping/refresh', [ShippingController::class, 'refresh'])->name('shipping.refresh');
        Route::patch('shipping/{carrier}/toggle', [ShippingController::class, 'toggle'])->name('shipping.toggle');
        Route::put('shipping/{carrier}', [ShippingController::class, 'update'])->name('shipping.update');
    });

    // Inventory (SMACC stock import).
    Route::get('stock-import', [StockImportController::class, 'index'])->middleware('permission:inventory.view')->name('stock-import.index');
    Route::get('stock-import/export', [StockImportController::class, 'export'])->middleware('permission:inventory.view')->name('stock-import.export');
    Route::middleware('permission:inventory.import')->group(function () {
        Route::post('stock-import/preview', [StockImportController::class, 'preview'])->name('stock-import.preview');
        Route::post('stock-import/apply', [StockImportController::class, 'apply'])->name('stock-import.apply');
        Route::post('stock-import/{activityLog}/undo', [StockImportController::class, 'undo'])->name('stock-import.undo');
    });

    // Change log — audit history + per-entry revert.
    Route::get('change-log', [ChangeLogController::class, 'index'])->middleware('permission:change_log.view')->name('change-log.index');
    // Bulk actions sit on their own paths, declared before the per-entry routes.
    // No collision is possible here (the per-entry routes carry a trailing
    // segment, so they differ in length), but keeping the literal paths first is
    // the habit that prevents `bulk-destroy` ever being read as an entry id.
    Route::post('change-log/bulk-revert', [ChangeLogController::class, 'bulkRevert'])->middleware('permission:change_log.revert')->name('change-log.bulk-revert');
    // 🔑 Deleting audit history is admin-only, deliberately NOT a grantable
    // permission: erasing the record of who changed what stays with the owner.
    Route::delete('change-log/bulk-destroy', [ChangeLogController::class, 'bulkDestroy'])->middleware('admin')->name('change-log.bulk-destroy');
    Route::post('change-log/{activityLog}/revert', [ChangeLogController::class, 'revert'])->middleware('permission:change_log.revert')->name('change-log.revert');
    Route::delete('change-log/undo/{section}', [ChangeLogController::class, 'dismissUndo'])->middleware('permission:change_log.view')->name('change-log.dismiss-undo');
});
