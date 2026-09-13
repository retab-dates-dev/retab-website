<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Services\ChangeLog\ChangeLogService;
use App\Support\Media;
use App\Support\ProductDescriptionWriter;
use App\Support\ProductNameTranslator;
use App\Support\TableExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Back-office catalogue management. Quantity-based inventory, no variants — one
 * product = one SMACC SKU. Stock edited here is the website mirror; the daily
 * SMACC import re-baselines it (see CLAUDE.md → POS).
 */
class ProductController extends Controller
{
    /** Session key holding the admin's last product-list query. */
    private const LIST_VIEW_KEY = 'admin.products.last_view';

    /** Columns the table may be sorted by (whitelist — never trust the raw param).
     *  'category' is virtual (sorts by the joined category name). */
    private const SORTABLE = ['name_ar', 'sku', 'smacc_sku', 'category', 'price', 'stock', 'is_active'];

    /** Full field set for the export (CSV / XLSX / JSON), in column order. */
    private const EXPORT_COLUMNS = [
        'id', 'name_ar', 'name_en', 'sku', 'smacc_sku', 'barcode', 'category',
        'price', 'sale_price', 'stock', 'low_stock_threshold', 'is_active',
        'is_featured', 'short_description_ar', 'short_description_en',
        'created_at', 'updated_at',
    ];

    public function index(Request $request)
    {
        $perPage = $this->perPage($request, 20);

        // Remember this list view so create/edit/delete can return the admin to
        // it. Only the whitelisted keys, so a stray query param can never be
        // replayed into a later redirect.
        $request->session()->put(self::LIST_VIEW_KEY, array_filter(
            $request->only(['search', 'category', 'status', 'sort', 'direction', 'per_page', 'page']),
            fn ($v) => $v !== null && $v !== '',
        ));

        $products = $this->filteredQuery($request)
            ->with(['category:id,name_ar,name_en', 'images'])
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Product $p) => [
                'id' => $p->id,
                'name_ar' => $p->name_ar,
                'name_en' => $p->name_en,
                'image' => Media::url($p->primaryImage()?->path, 'thumb'),
                // All images (detail-size) so the admin can open + swap them in the viewer.
                'images' => $p->images->sortBy('sort_order')->map(fn ($img) => Media::url($img->path, 'detail'))->filter()->values(),
                'sku' => $p->sku,
                'smacc_sku' => $p->smacc_sku,
                'category' => $p->category?->only('name_ar', 'name_en'),
                'price' => (float) $p->price,
                'sale_price' => $p->sale_price !== null ? (float) $p->sale_price : null,
                'stock' => $p->stock,
                'is_low_stock' => $p->isLowStock(),
                'is_active' => $p->is_active,
                'is_featured' => $p->is_featured,
                'is_coming_soon' => $p->is_coming_soon,
                // Completeness flags — what a draft still needs before it can go live.
                'needs_price' => (float) $p->price <= 0,
                'needs_image' => $p->images->isEmpty(),
                'needs_name_en' => trim((string) $p->name_en) === '',
                'needs_description' => trim((string) $p->description_ar) === '' && trim((string) $p->short_description_ar) === '',
            ]);

        return Inertia::render('admin/products/index', [
            'products' => $products,
            'filters' => [
                'search' => $request->query('search'),
                'category' => $request->query('category') ? (int) $request->query('category') : null,
                'status' => in_array($request->query('status'), ['active', 'draft', 'coming_soon', 'incomplete'], true) ? $request->query('status') : null,
                'sort' => in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : null,
                'direction' => $request->query('direction') === 'asc' ? 'asc' : 'desc',
                'per_page' => $perPage,
            ],
            'draftCount' => Product::where('is_active', false)->count(),
            'categories' => $this->categoryOptions(),
            'undoMeta' => session('undo:products'),
        ]);
    }

    /**
     * Shared list query for the table and the export: search (name/sku/smacc),
     * category filter, and a whitelisted sort (falls back to newest-first).
     */
    private function filteredQuery(Request $request)
    {
        $search = $request->query('search');
        $categoryId = $request->query('category');
        $status = $request->query('status');
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : null;
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        // Columns qualified with products.* so they stay unambiguous once the
        // category sort adds a join (categories also has name_ar/name_en/slug).
        $query = Product::query()
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->where('products.name_ar', 'like', "%{$search}%")
                ->orWhere('products.name_en', 'like', "%{$search}%")
                ->orWhere('products.sku', 'like', "%{$search}%")
                ->orWhere('products.smacc_sku', 'like', "%{$search}%")))
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            // Drafts = hidden products (the workspace to finish + optionally flag Coming Soon).
            ->when($status === 'active', fn ($q) => $q->where('products.is_active', true))
            ->when($status === 'draft', fn ($q) => $q->where('products.is_active', false))
            ->when($status === 'coming_soon', fn ($q) => $q->where('products.is_coming_soon', true))
            // Blocked by the publish guard — same definition the dashboard tile
            // counts, so the number there and this list can never disagree.
            ->when($status === 'incomplete', fn ($q) => $q->incompleteForPublish());

        if ($sort === 'category') {
            $query->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->orderBy('categories.name_ar', $direction)
                ->select('products.*');
        } elseif ($sort) {
            $query->orderBy($sort, $direction);
        } else {
            $query->latest();
        }

        return $query;
    }

    /**
     * Download the (filtered) catalogue as CSV, XLSX or JSON. Same filters/sort
     * as the table, so you export exactly what you're looking at.
     */
    public function export(Request $request)
    {
        $format = in_array($request->query('format'), ['csv', 'xlsx', 'json'], true)
            ? $request->query('format')
            : 'csv';

        $rows = $this->filteredQuery($request)
            ->with('category:id,name_ar')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name_ar' => $p->name_ar,
                'name_en' => $p->name_en,
                'sku' => $p->sku,
                'smacc_sku' => $p->smacc_sku,
                'barcode' => $p->barcode,
                'category' => $p->category?->name_ar,
                'price' => (float) $p->price,
                'sale_price' => $p->sale_price !== null ? (float) $p->sale_price : null,
                'stock' => $p->stock,
                'low_stock_threshold' => $p->low_stock_threshold,
                'is_active' => (int) $p->is_active,
                'is_featured' => (int) $p->is_featured,
                'short_description_ar' => $p->short_description_ar,
                'short_description_en' => $p->short_description_en,
                'created_at' => $p->created_at?->toDateTimeString(),
                'updated_at' => $p->updated_at?->toDateTimeString(),
            ]);

        return TableExport::download($format, 'products', self::EXPORT_COLUMNS, $rows);
    }

    public function create()
    {
        return Inertia::render('admin/products/form', [
            'product' => null,
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function store(Request $request, ChangeLogService $changeLog)
    {
        $data = $this->validateProduct($request);
        $options = $this->validateOptions($request);

        // Every product must ship with at least one image — collected in the
        // create form and sent with it. First image becomes the primary.
        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:8'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ], ['images.required' => __('messages.admin.product_needs_image')]);
        $images = array_values($request->file('images'));

        DB::transaction(function () use ($data, $options, $images, $changeLog) {
            $product = Product::create($data);
            $this->syncOptions($product, $options);

            foreach ($images as $i => $file) {
                $product->images()->create([
                    'path' => Media::storeImage($file, "products/{$product->id}"),
                    'sort_order' => $i + 1,
                    'is_primary' => $i === 0,
                ]);
            }

            // The row is created before its images exist, so the publish guard
            // will have forced it hidden. Re-apply what the admin actually asked
            // for now the images are attached — the guard runs again on this save
            // and still refuses if anything else is missing.
            if (($data['is_active'] ?? false) && ! $product->is_active) {
                $product->forceFill(['is_active' => true])->save();
            }

            $changeLog->logCreated($product, $product->name_ar);
        });

        return $this->listRedirect()->with('success', __('messages.admin.product_created'));
    }

    public function edit(Product $product)
    {
        return Inertia::render('admin/products/form', [
            'product' => $this->productData($product),
            'categories' => $this->categoryOptions(),
        ]);
    }

    /** JSON product payload for the in-list edit modal (same shape as the edit page). */
    public function detail(Product $product)
    {
        return response()->json(['product' => $this->productData($product)]);
    }

    /**
     * Full editable product payload (fields + images), shared by the edit page
     * and the in-list modal.
     *
     * @return array<string, mixed>
     */
    private function productData(Product $product): array
    {
        $product->load('images', 'options');

        return [
            'id' => $product->id,
            'category_id' => $product->category_id,
            'options' => $product->options->map(fn ($o) => [
                'id' => $o->id,
                'label_ar' => $o->label_ar,
                'label_en' => $o->label_en,
                'amount' => $o->amount,
                'is_box' => (bool) $o->is_box,
                'price' => (float) $o->price,
                'price_overridden' => (bool) $o->price_overridden,
                'stock_units' => $o->stock_units,
                'is_active' => (bool) $o->is_active,
            ])->values(),
            'name_ar' => $product->name_ar,
            'name_en' => $product->name_en,
            'slug' => $product->slug,
            'description_ar' => $product->description_ar,
            'description_en' => $product->description_en,
            'price' => (float) $product->price,
            'base_weight_grams' => $product->base_weight_grams,
            'sale_price' => $product->sale_price !== null ? (float) $product->sale_price : null,
            'sku' => $product->sku,
            'smacc_sku' => $product->smacc_sku,
            'barcode' => $product->barcode,
            'stock' => $product->stock,
            'low_stock_threshold' => $product->low_stock_threshold,
            'is_active' => $product->is_active,
            'is_featured' => $product->is_featured,
            'is_coming_soon' => $product->is_coming_soon,
            'available_until' => $product->available_until?->toDateTimeString(),
            'sale_applies_to_options' => (bool) $product->sale_applies_to_options,
            'images' => $product->images->sortBy('sort_order')->values()->map(fn ($img) => [
                'id' => $img->id,
                'url' => Media::url($img->path, 'card'),
                'is_primary' => $img->is_primary,
            ]),
        ];
    }

    public function update(Request $request, Product $product, ChangeLogService $changeLog)
    {
        $data = $this->validateProduct($request, $product);
        $options = $this->validateOptions($request);

        // 🔑 Deliberately NOT refusing the save when something is missing. This
        // used to throw when a product had no images, which meant the incomplete
        // products — precisely the ones needing attention — could not be edited
        // at all. The publish guard on the model hides them instead, so work in
        // progress is always saveable.
        $wanted = (bool) ($data['is_active'] ?? false);

        DB::transaction(function () use ($product, $data, $options, $changeLog) {
            $before = $product->attributesToArray();
            $product->update($data);
            $this->syncOptions($product, $options);
            $changeLog->logUpdated($product, $before, $product->name_ar);
        });

        // Say so when the guard overrode the request, rather than letting the
        // admin believe the product went live.
        if ($wanted && ! $product->is_active) {
            return $this->listRedirect()
                ->with('error', $this->blockedMessage($product));
        }

        return $this->listRedirect()->with('success', __('messages.admin.product_updated'));
    }

    /**
     * Quick show/hide from the list — flips is_active. Logged + revertable like
     * a normal edit.
     *
     * Refuses up front when the product is not publishable, so the admin gets a
     * message naming what is missing rather than clicking a toggle that silently
     * springs back (which is what the model guard alone would look like).
     */
    public function toggleActive(Product $product, ChangeLogService $changeLog)
    {
        if (! $product->is_active && ! $product->isPublishable()) {
            return back()->with('error', $this->blockedMessage($product));
        }

        DB::transaction(function () use ($product, $changeLog) {
            $before = $product->attributesToArray();
            $product->update(['is_active' => ! $product->is_active]);
            $changeLog->logUpdated($product, $before, $product->name_ar);
        });

        return back()->with('success', __($product->is_active ? 'messages.admin.product_activated' : 'messages.admin.product_deactivated'));
    }

    /**
     * Where to send an admin after creating, editing or deleting a product:
     * back to the list AS THEY LEFT IT.
     *
     * 🔑 A bare redirect to the index drops the query string, so an admin who
     * filtered to "Hidden · Premium Dates", opened a product and saved it landed
     * on an unfiltered page 1 and had to set the filters up again for every
     * single row they were working through.
     *
     * The last list view is remembered in the SESSION rather than threaded
     * through every form, because the edit screen is also reached from the
     * dashboard and from global search, where there is no filter state to carry.
     *
     * ⚠️ It is a mirror of the last list request, not a sticky preference:
     * index() overwrites it on every visit, so arriving from the sidebar with no
     * query string records none and the admin is never surprised by filters they
     * did not just set.
     */
    private function listRedirect(): RedirectResponse
    {
        return redirect()->route('admin.products.index', session(self::LIST_VIEW_KEY, []));
    }

    public function destroy(Product $product, ChangeLogService $changeLog)
    {
        DB::transaction(function () use ($product, $changeLog) {
            $product->delete(); // soft delete — preserves order history references
            $changeLog->logDeleted($product, $product->name_ar);
        });

        return $this->listRedirect()->with('success', __('messages.admin.product_deleted'));
    }

    /**
     * Shared validation for create + update. On update, unique rules ignore the
     * current product. Slug auto-derives from name_en / sku when left blank.
     *
     * @return array<string, mixed>
     */
    /**
     * Suggestions for the product form: an English name from the Arabic one, and
     * a bilingual description from the product's own attributes.
     *
     * 🔑 A server endpoint rather than porting the dictionaries to TypeScript.
     * They would then exist twice and drift — the exact failure this codebase
     * has already been bitten by (see the duplicated search `normalize`, which
     * needs a shared 18-case table to stay honest).
     *
     * Works for an UNSAVED product: nothing here is persisted, the description
     * writer is handed a transient model built from what the form has typed so
     * far. Both answers are suggestions the admin edits before saving.
     */
    public function suggest(Request $request, ProductNameTranslator $names, ProductDescriptionWriter $descriptions): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
        ]);

        $suggestedEn = $names->translate($data['name_ar'] ?? null);

        $transient = new Product([
            'name_ar' => $data['name_ar'] ?? '',
            // Prefer what the admin has already typed; fall back to the suggestion
            // so the description reads naturally even before the name is filled in.
            'name_en' => ($data['name_en'] ?? '') ?: ($suggestedEn ?? ''),
        ]);
        $transient->setRelation('category', isset($data['category_id']) ? Category::find($data['category_id']) : null);

        return response()->json([
            // null when a word is not in the dictionary — the UI says so rather
            // than pasting a confidently-wrong name into the field.
            'name_en' => $suggestedEn,
            'description' => $descriptions->write($transient),
        ]);
    }

    /**
     * "Can't be shown yet — still needs: a price, an English name."
     *
     * Names every missing item in one message rather than reporting them one at
     * a time: an admin fixing a product should learn the whole list on the first
     * attempt, not discover a second problem after fixing the first.
     */
    private function blockedMessage(Product $product): string
    {
        $missing = array_map(
            fn (string $key) => __("messages.admin.publish_requirement.{$key}"),
            $product->missingForPublish(),
        );

        return __('messages.admin.product_publish_blocked', [
            // Separator is localized: Arabic uses ، where English uses a comma.
            'missing' => implode(__('messages.admin.publish_requirement.separator'), $missing),
        ]);
    }

    private function validateProduct(Request $request, ?Product $product = null): array
    {
        $id = $product?->id;

        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:191', Rule::unique('products', 'slug')->ignore($id)],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'lt:price'],
            'base_weight_grams' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'sale_applies_to_options' => ['boolean'],
            'sku' => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($id)],
            'smacc_sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'smacc_sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:100'],
            'stock' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'is_featured' => ['boolean'],
            'is_coming_soon' => ['boolean'],
            // Optional end of a time-boxed product's life on the store. Past dates
            // are allowed: saving one simply hides the product (see Product's guard).
            'available_until' => ['nullable', 'date'],
        ]);

        if (empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['name_en'] ?? $data['sku'], $id);
        }

        return $data;
    }

    /**
     * Validate the size/packaging options sent alongside the product. Returns a
     * clean array (empty when the product has no options). The client sends the
     * FINAL per-option prices (auto-scaled + overridden there); the server trusts
     * the numbers but re-validates shape and bounds.
     *
     * @return list<array<string, mixed>>
     */
    private function validateOptions(Request $request): array
    {
        $request->validate([
            'options' => ['nullable', 'array', 'max:20'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.label_ar' => ['required', 'string', 'max:100'],
            'options.*.label_en' => ['nullable', 'string', 'max:100'],
            'options.*.amount' => ['nullable', 'integer', 'min:1'],
            'options.*.is_box' => ['boolean'],
            'options.*.price' => ['required', 'numeric', 'min:0'],
            'options.*.price_overridden' => ['boolean'],
            'options.*.stock_units' => ['required', 'integer', 'min:1'],
            'options.*.is_active' => ['boolean'],
        ]);

        return collect($request->input('options', []))->values()->map(fn ($o, $i) => [
            'id' => $o['id'] ?? null,
            'label_ar' => trim($o['label_ar']),
            'label_en' => isset($o['label_en']) && trim($o['label_en']) !== '' ? trim($o['label_en']) : null,
            'amount' => $o['amount'] ?? null,
            'is_box' => (bool) ($o['is_box'] ?? false),
            'price' => $o['price'],
            'price_overridden' => (bool) ($o['price_overridden'] ?? false),
            'stock_units' => $o['stock_units'] ?? 1,
            'is_active' => (bool) ($o['is_active'] ?? true),
            'sort_order' => $i + 1,
        ])->all();
    }

    /**
     * Reconcile a product's options with the submitted set: update the rows that
     * carry an id, create the new ones, delete the rest. Ids are checked to
     * belong to this product so a forged id can't hijack another product's option.
     *
     * @param  list<array<string, mixed>>  $options
     */
    private function syncOptions(Product $product, array $options): void
    {
        $keepIds = [];

        foreach ($options as $o) {
            $attributes = collect($o)->except('id')->all();

            $existing = $o['id'] ? $product->options()->whereKey($o['id'])->first() : null;
            if ($existing) {
                $existing->update($attributes);
                $keepIds[] = $existing->id;
            } else {
                $keepIds[] = $product->options()->create($attributes)->id;
            }
        }

        $product->options()->whereKeyNot($keepIds)->delete();
    }

    /**
     * Slugify the source (or fall back to the SKU), then suffix to stay unique.
     */
    private function uniqueSlug(string $source, ?int $ignoreId): string
    {
        $base = Str::slug($source) ?: Str::slug('product-'.Str::random(6));
        $slug = $base;
        $i = 2;

        while (Product::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * @return array<int, array{id: int, name_ar: string, name_en: string|null}>
     */
    private function categoryOptions(): array
    {
        // Only LEAF categories are assignable to a product. The top-level nav groups
        // (التمور / الهدايا) exist purely to drive the storefront navbar, so excluding
        // them also drops the duplicate "Dates" (parent group vs leaf) from the list.
        // name_en ships too so the EN-first admin can localize the labels.
        return Category::whereNotNull('parent_id')
            ->orderBy('sort_order')
            ->get(['id', 'name_ar', 'name_en'])
            ->map(fn (Category $c) => ['id' => $c->id, 'name_ar' => $c->name_ar, 'name_en' => $c->name_en])
            ->all();
    }
}
