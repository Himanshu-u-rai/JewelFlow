<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Vendor;
use App\Models\AuditLog;
use App\Services\CatalogShareService;
use App\Services\ItemManufacturingService;
use App\Services\RetailerReportService;
use App\Services\ShopPricingService;
use App\Services\MetalRegistry;
use App\Http\Concerns\RespondsDynamically;
use App\Support\ShopEdition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ItemController extends Controller
{
    use RespondsDynamically;

    private const MAX_ITEM_GALLERY_IMAGES = 4;

    private const ITEM_GALLERY_IMAGE_MIMES = 'jpg,jpeg,png,gif,webp,avif,bmp';

    public function __construct(
        private CatalogShareService $catalog,
        private ShopPricingService $pricing
    ) {}
    /**
     * Display all items (stock)
     */
    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        $shop   = auth()->user()->shop;
        $isRetailer = $shop->isRetailer();
        $stockValueDisplay = $shop->preferences?->stock_value_display ?? 'total';

        // Categories for filter — loaded once, reused for name lookup below
        $categories = Category::where('shop_id', $shopId)
            ->with('subCategories')
            ->get();

        $query = Item::where('shop_id', $shopId);

        // Filter by status — validate against allowlist. Default to in_stock so sold items don't leak into the main list.
        $allowedStatuses = ['in_stock', 'sold'];
        $statusFilter = $request->filled('status') && in_array($request->status, $allowedStatuses, true)
            ? $request->status
            : 'in_stock';
        $query->where('status', $statusFilter);

        // Filter by category — use already-loaded collection, no extra query
        if ($request->filled('category')) {
            $cat = $categories->firstWhere('id', (int) $request->category);
            if ($cat) {
                $query->where('category', $cat->name);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // Filter by purity — validate against allowlist
        if ($request->filled('purity') && is_numeric($request->purity) && (float) $request->purity > 0) {
            $query->where('purity', (float) $request->purity);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('barcode', 'ilike', "%{$search}%")
                  ->orWhere('design', 'ilike', "%{$search}%")
                  ->orWhere('category', 'ilike', "%{$search}%");
            });
        }

        $items = $query->orderBy('created_at', 'desc')->paginate(20)->withQueryString();

        // Stats — single query with FILTER aggregates instead of 4 separate queries
        $rawStats = DB::table('items')
            ->where('shop_id', $shopId)
            ->selectRaw("
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE status = 'in_stock') as in_stock,
                COUNT(*) FILTER (WHERE status = 'sold') as sold,
                COALESCE(SUM(selling_price) FILTER (WHERE status = 'in_stock'), 0) as total_stock_value,
                COALESCE(SUM(net_metal_weight * purity / 24.0) FILTER (WHERE status = 'in_stock'), 0) as total_fine_gold
            ")
            ->first();

        $stats = [
            'total'    => (int) $rawStats->total,
            'in_stock' => (int) $rawStats->in_stock,
            'sold'     => (int) $rawStats->sold,
        ];

        if ($isRetailer) {
            $stats['total_stock_value'] = (float) $rawStats->total_stock_value;

            $metalRows = DB::table('items')
                ->where('shop_id', $shopId)
                ->where('status', 'in_stock')
                ->whereNotNull('metal_type')
                ->selectRaw('metal_type as metal, COALESCE(SUM(net_metal_weight), 0) as total_weight')
                ->groupBy('metal')
                ->pluck('total_weight', 'metal');

            $stats['metal_holdings'] = [
                'gold'     => (float) ($metalRows['gold']     ?? 0),
                'silver'   => (float) ($metalRows['silver']   ?? 0),
            ];
        } else {
            $stats['total_fine_gold'] = (float) $rawStats->total_fine_gold;
        }

        // Stock aging & sellers data for retailers
        $stockAgingData = null;
        $sellersData = null;
        $pricingAlertCount = 0;
        if ($isRetailer) {
            $reportService = app(RetailerReportService::class);
            $stockAgingData = $reportService->stockAging();

            $period = $request->input('seller_period', '30');
            $sellersData = [
                'best' => $reportService->bestSellers(10, $period),
                'worst' => $reportService->worstSellers(10, $period),
                'period' => $period,
            ];

            $pricingAlertCount = $this->pricing->pricingAlerts($shop)['count'];
        }

        return view('inventory.items.index', compact('items', 'stats', 'categories', 'stockAgingData', 'sellersData', 'isRetailer', 'statusFilter', 'pricingAlertCount', 'stockValueDisplay'));
    }

    /**
     * JSON endpoint for the pricing alert drawer.
     * Returns items whose price was not updated in the last reprice run.
     * Retailer-only; scoped to the authenticated shop.
     */
    public function pricingAlerts()
    {
        $shop = auth()->user()->shop;

        if (! $shop || ! $shop->isRetailer()) {
            return response()->json(['count' => 0, 'items' => []]);
        }

        $alerts = $this->pricing->pricingAlerts($shop);

        return response()->json([
            'count' => $alerts['count'],
            'items' => $alerts['items']->map(fn ($item) => [
                'id'       => $item->id,
                'barcode'  => $item->barcode,
                'design'   => $item->design ?: null,
                'category' => $item->category ?: null,
                'purity'   => $item->purity,
                'metal_type'           => $item->metal_type,
                'pricing_review_notes' => $item->pricing_review_notes,
                'edit_url' => route('inventory.items.edit', $item->id),
            ])->values(),
        ]);
    }

    /**
     * Show form to create new item — view adapts based on shop edition
     */
    public function create(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        $shop = auth()->user()->shop;
        $categories = Category::where('shop_id', $shopId)
            ->with('subCategories')
            ->get();

        // Retailer edition: simple form — no lots, no wastage
        if ($shop->isRetailer()) {
            if (! auth()->user()->isOwner() && ! $this->pricing->hasCurrentDailyRates($shop)) {
                return redirect()->route('inventory.items.index')
                    ->with('error', 'Today\'s retailer pricing is missing. Ask the owner to save today\'s Pricing rates first.');
            }

            $vendors = Vendor::where('shop_id', $shopId)->active()->orderBy('name')->get();
            $karigars = \App\Models\Karigar::where('shop_id', $shopId)->active()->orderBy('name')->get();
            $purityProfiles = $this->pricing->activePurityProfiles($shop)->groupBy('metal_type');
            $resolvedRates = $this->buildRetailerResolvedRateMap($shop, $purityProfiles);
            [$enabledMetals, $metalUxModes] = $this->buildMetalPickerData($shopId);

            return view('inventory.items.create-retailer', compact(
                'categories',
                'vendors',
                'karigars',
                'purityProfiles',
                'resolvedRates',
                'enabledMetals',
                'metalUxModes'
            ));
        }

        // Manufacturer edition: full form with lots, products, wastage
        $lots = MetalLot::where('shop_id', $shopId)
            ->where('fine_weight_remaining', '>', 0)
            ->orderBy('created_at', 'desc')
            ->get();

        $products = \App\Models\Product::where('shop_id', $shopId)
            ->with(['category', 'subCategory'])
            ->orderBy('name')
            ->get();

        $selectedProductId = null;
        if ($request->filled('product_id')) {
            $selectedProductId = \App\Models\Product::where('shop_id', $shopId)
                ->where('id', $request->integer('product_id'))
                ->value('id');
        }

        return view('inventory.items.create', compact('lots', 'categories', 'products', 'selectedProductId'));
    }

    /**
     * Store a newly created item
     */
    public function store(Request $request)
    {
        $shop = auth()->user()->shop;
        $shopId = auth()->user()->shop_id;

        // === Retailer edition: simple direct item creation ===
        if ($shop->isRetailer()) {
            return $this->storeRetailerItem($request, $shopId);
        }

        // === Manufacturer edition: lot-based manufacturing ===
        $validated = $request->validate([
            'barcode' => [
                'required',
                'string',
                'max:100',
                Rule::unique('items', 'barcode')->where('shop_id', $shopId),
            ],
            'metal_lot_id' => [
                'required',
                Rule::exists('metal_lots', 'id')->where('shop_id', $shopId),
            ],
            'product_id' => [
                'nullable',
                Rule::exists('products', 'id')->where('shop_id', $shopId),
            ],
            'design' => 'nullable|string|max:255',
            'category' => 'required|string|max:255',
            'sub_category' => 'nullable|string|max:255',
            'gross_weight' => 'required|numeric|min:0.001',
            'stone_weight' => 'nullable|numeric|min:0',
            'purity' => 'required|numeric|min:1|max:24',
            'wastage_percent' => 'nullable|numeric|min:0|max:50',
            'making_charges' => 'nullable|numeric|min:0',
            'stone_charges' => 'nullable|numeric|min:0',
            'image' => 'nullable|file|mimes:jpeg,png,gif,webp|max:5120',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('items', 'public');
        }

        $payload = array_merge($validated, [
            'image' => $imagePath,
        ]);

        try {
            app(ItemManufacturingService::class)->manufacture($shopId, auth()->id(), $payload);
        } catch (\Throwable $e) {
            return back()->withErrors(['metal_lot_id' => $e->getMessage()])->withInput();
        }

        return redirect()->route('inventory.items.index')
            ->with('success', 'Item created successfully! Barcode: ' . $validated['barcode']);
    }

    /**
     * Store a retailer item — no lot deduction, direct creation with selling price
     */
    private function storeRetailerItem(Request $request, int $shopId)
    {
        $validated = $request->validate([
            'barcode' => [
                'required',
                'string',
                'max:100',
                Rule::unique('items', 'barcode')->where('shop_id', $shopId),
            ],
            'design' => 'nullable|string|max:255',
            'category' => 'required|string|max:255',
            'metal_type' => ['required', Rule::in(MetalRegistry::enabledMetalsForShop($shopId))],
            'sub_category' => 'nullable|string|max:255',
            'gross_weight' => 'required|numeric|min:0.001',
            'stone_weight' => 'nullable|numeric|min:0',
            'purity' => 'required|numeric|min:0.001|max:1000',
            'cost_price' => 'nullable|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'making_charges' => 'nullable|numeric|min:0',
            'stone_charges' => 'nullable|numeric|min:0',
            'hallmark_charges' => 'nullable|numeric|min:0',
            'rhodium_charges' => 'nullable|numeric|min:0',
            'other_charges' => 'nullable|numeric|min:0',
            'vendor_id' => ['nullable', Rule::exists('vendors', 'id')->where('shop_id', $shopId)],
            'karigar_id' => ['nullable', Rule::exists('karigars', 'id')->where('shop_id', $shopId)],
            'huid' => ['nullable', 'string', 'max:30', Rule::unique('items', 'huid')->where('shop_id', $shopId)->whereNotNull('huid')],
            'hallmark_date' => 'nullable|date',
            'image' => 'nullable|file|mimes:' . self::ITEM_GALLERY_IMAGE_MIMES . '|max:5120',
            'images' => 'nullable|array|max:' . self::MAX_ITEM_GALLERY_IMAGES,
            'images.*' => 'file|mimes:' . self::ITEM_GALLERY_IMAGE_MIMES . '|max:5120',
        ]);

        try {
            $pricing = $this->pricing->computeRetailerCostPayload(auth()->user()->shop, $validated);
        } catch (\Throwable $e) {
            return back()->withErrors(['pricing' => $e->getMessage()])->withInput();
        }

        $imagePaths = $this->storeUploadedItemGallery($this->uploadedItemGalleryFiles($request));
        $imagePath = $imagePaths[0] ?? null;

        try {
            $item = DB::transaction(function () use ($shopId, $validated, $pricing, $imagePaths, $imagePath) {
                $item = Item::create([
                    'shop_id' => $shopId,
                    'barcode' => $validated['barcode'],
                    'design' => $validated['design'] ?? null,
                    'category' => $validated['category'],
                    'metal_type' => $pricing['metal_type'],
                    'sub_category' => $validated['sub_category'] ?? null,
                    'gross_weight' => $validated['gross_weight'],
                    'stone_weight' => $validated['stone_weight'] ?? 0,
                    'net_metal_weight' => $pricing['net_metal_weight'],
                    'purity' => $pricing['purity'],
                    'making_charges' => $validated['making_charges'] ?? 0,
                    'stone_charges' => $validated['stone_charges'] ?? 0,
                    'hallmark_charges' => $validated['hallmark_charges'] ?? 0,
                    'rhodium_charges' => $validated['rhodium_charges'] ?? 0,
                    'other_charges' => $validated['other_charges'] ?? 0,
                    'cost_price' => $pricing['cost_price'],
                    'selling_price' => $pricing['selling_price'],
                    'vendor_id' => $validated['vendor_id'] ?? null,
                    'karigar_id' => $validated['karigar_id'] ?? null,
                    'huid' => $validated['huid'] ?? null,
                    'hallmark_date' => $validated['hallmark_date'] ?? null,
                    'source' => 'purchased',
                    'status' => 'in_stock',
                    'image' => $imagePath,
                    'images' => $imagePaths ?: null,
                    'pricing_review_required' => false,
                ]);

                AuditLog::create([
                    'shop_id' => $shopId,
                    'user_id' => auth()->id(),
                    'action' => 'item_created',
                    'model_type' => 'item',
                    'model_id' => $item->id,
                    'data' => [
                        'barcode' => $item->barcode,
                        'source' => 'purchased',
                        'selling_price' => $item->selling_price,
                        'cost_price' => $item->cost_price,
                        'client_cost_price_ignored' => array_key_exists('cost_price', $validated),
                    ],
                ]);

                return $item;
            });
        } catch (\Throwable $e) {
            $this->deleteItemGalleryFiles($imagePaths);
            throw $e;
        }

        return redirect()->route('inventory.items.index')
            ->with('success', 'Item added to stock! Barcode: ' . $validated['barcode']);
    }

    /**
     * Show item details
     */
    public function show(Item $item)
    {
        $this->authorize('view', $item);

        $shop = auth()->user()->shop;
        $lot  = $shop->isManufacturer() ? MetalLot::where('shop_id', auth()->user()->shop_id)->find($item->metal_lot_id) : null;

        if ($shop->isRetailer()) {
            $item->load('vendor', 'stockPurchase');
        }

        $token          = $this->catalog->ensureItemHasToken($item);
        $publicShareUrl = $this->catalog->buildPreferredProductUrl($shop, $token);
        $isRetailer     = $shop->isRetailer();

        return view('inventory.items.show', compact('item', 'lot', 'publicShareUrl', 'isRetailer'));
    }

    /**
     * Show form to edit item
     */
    public function edit(Item $item)
    {
        $this->authorize('update', $item);

        // Only allow editing in_stock items
        if ($item->status !== 'in_stock') {
            return redirect()->route('inventory.items.show', $item)
                ->with('error', 'Only items in stock can be edited.');
        }

        $shop = auth()->user()->shop;
        $shopId = auth()->user()->shop_id;
        $categories = Category::where('shop_id', $shopId)->get();

        if ($shop->isRetailer()) {
            if (! auth()->user()->isOwner() && ! $this->pricing->hasCurrentDailyRates($shop)) {
                return redirect()->route('inventory.items.show', $item)
                    ->with('error', 'Today\'s retailer pricing is missing. Ask the owner to save today\'s Pricing rates first.');
            }

            $vendors = Vendor::where('shop_id', $shopId)->active()->orderBy('name')->get();
            $karigars = \App\Models\Karigar::where('shop_id', $shopId)->active()->orderBy('name')->get();
            $purityProfiles = $this->pricing->activePurityProfiles($shop)->groupBy('metal_type');
            $resolvedRates = $this->buildRetailerResolvedRateMap($shop, $purityProfiles);
            [$enabledMetals, $metalUxModes] = $this->buildMetalPickerData($shopId);

            return view('inventory.items.edit-retailer', compact(
                'item',
                'categories',
                'vendors',
                'karigars',
                'purityProfiles',
                'resolvedRates',
                'enabledMetals',
                'metalUxModes'
            ));
        }

        return view('inventory.items.edit', compact('item', 'categories'));
    }

    /**
     * Update item
     */
    public function update(Request $request, Item $item)
    {
        $this->authorize('update', $item);

        // Only allow updating in_stock items
        if ($item->status !== 'in_stock') {
            return redirect()->route('inventory.items.show', $item)
                ->with('error', 'Only items in stock can be edited.');
        }

        $shop = auth()->user()->shop;

        // === Retailer edition: can edit prices and more fields ===
        if ($shop->isRetailer()) {
            return $this->updateRetailerItem($request, $item);
        }

        // === Manufacturer edition: limited edits (no weight/purity changes) ===
        $validated = $request->validate([
            'barcode' => [
                'required',
                'string',
                'max:100',
                Rule::unique('items', 'barcode')
                    ->where('shop_id', auth()->user()->shop_id)
                    ->ignore($item->id),
            ],
            'design' => 'nullable|string|max:255',
            'category' => 'required|string|max:255',
            'sub_category' => 'nullable|string|max:255',
            'making_charges' => 'nullable|numeric|min:0',
            'stone_charges' => 'nullable|numeric|min:0',
            'image' => 'nullable|file|mimes:jpeg,png,gif,webp|max:5120',
            'remove_image' => 'nullable|boolean',
        ]);

        // Handle image upload
        $imagePath = $item->image; // Keep existing by default
        
        if ($request->hasFile('image')) {
            if ($item->image && Storage::disk('public')->exists($item->image)) {
                Storage::disk('public')->delete($item->image);
            }
            $imagePath = $request->file('image')->store('items', 'public');
        } elseif ($request->boolean('remove_image')) {
            if ($item->image && Storage::disk('public')->exists($item->image)) {
                Storage::disk('public')->delete($item->image);
            }
            $imagePath = null;
        }

        // Note: We don't allow changing weight/purity as that would affect gold accounting
        // Only cosmetic, image, and charge fields can be updated

        $item->update([
            'barcode' => $validated['barcode'],
            'design' => $validated['design'] ?? $item->design,
            'category' => $validated['category'],
            'sub_category' => $validated['sub_category'] ?? null,
            'making_charges' => $validated['making_charges'] ?? $item->making_charges,
            'stone_charges' => $validated['stone_charges'] ?? $item->stone_charges,
            'image' => $imagePath,
        ]);

        // Recalculate cost price if charges changed (lot may have been deleted)
        $lot = MetalLot::where('shop_id', auth()->user()->shop_id)->find($item->metal_lot_id);
        if ($lot) {
            $fineGold = $item->net_metal_weight * ($item->purity / 24) + ($item->wastage ?? 0);
            $goldCost = $fineGold * ($lot->cost_per_fine_gram ?? 0);
            $item->cost_price = $goldCost + $item->making_charges + $item->stone_charges;
            $item->save();
        }

        // Audit log
        AuditLog::create([
            'shop_id' => $item->shop_id,
            'user_id' => auth()->id(),
            'action' => 'item_updated',
            'model_type' => 'item',
            'model_id' => $item->id,
            'data' => array_merge(collect($validated)->except(['image', 'remove_image'])->all(), [
                'cost_price' => $item->cost_price,
                'client_cost_price_ignored' => array_key_exists('cost_price', $validated),
            ]),
        ]);

        return redirect()->route('inventory.items.show', $item)
            ->with('success', 'Item updated successfully.');
    }

    /**
     * Update a retailer item — full edit including prices
     */
    private function updateRetailerItem(Request $request, Item $item)
    {
        $shopId = auth()->user()->shop_id;
        $validated = $request->validate([
            'barcode' => [
                'required',
                'string',
                'max:100',
                Rule::unique('items', 'barcode')
                    ->where('shop_id', $shopId)
                    ->ignore($item->id),
            ],
            'design' => 'nullable|string|max:255',
            'category' => 'required|string|max:255',
            'metal_type' => ['required', Rule::in(MetalRegistry::enabledMetalsForShop($shopId))],
            'sub_category' => 'nullable|string|max:255',
            'gross_weight' => 'required|numeric|min:0.001',
            'stone_weight' => 'nullable|numeric|min:0',
            'purity' => 'required|numeric|min:0.001|max:1000',
            'cost_price' => 'nullable|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'making_charges' => 'nullable|numeric|min:0',
            'stone_charges' => 'nullable|numeric|min:0',
            'hallmark_charges' => 'nullable|numeric|min:0',
            'rhodium_charges' => 'nullable|numeric|min:0',
            'other_charges' => 'nullable|numeric|min:0',
            'vendor_id' => ['nullable', Rule::exists('vendors', 'id')->where('shop_id', $shopId)],
            'karigar_id' => ['nullable', Rule::exists('karigars', 'id')->where('shop_id', $shopId)],
            'huid' => ['nullable', 'string', 'max:30', Rule::unique('items', 'huid')->where('shop_id', $shopId)->whereNotNull('huid')->ignore($item->id)],
            'hallmark_date' => 'nullable|date',
            'image' => 'nullable|file|mimes:' . self::ITEM_GALLERY_IMAGE_MIMES . '|max:5120',
            'remove_image' => 'nullable|boolean',
            'remove_images' => 'nullable|array|max:' . self::MAX_ITEM_GALLERY_IMAGES,
            'remove_images.*' => 'string',
            'images' => 'nullable|array|max:' . self::MAX_ITEM_GALLERY_IMAGES,
            'images.*' => 'file|mimes:' . self::ITEM_GALLERY_IMAGE_MIMES . '|max:5120',
        ]);

        try {
            $pricing = $this->pricing->computeRetailerCostPayload(auth()->user()->shop, $validated);
        } catch (\Throwable $e) {
            return back()->withErrors(['pricing' => $e->getMessage()])->withInput();
        }

        $currentGallery = $item->image_gallery;
        $removeImages = $request->boolean('remove_image')
            ? $currentGallery
            : collect($request->input('remove_images', []))
                ->map(fn ($path) => is_string($path) ? trim($path) : null)
                ->filter()
                ->values()
                ->all();

        $remainingGallery = collect($currentGallery)
            ->reject(fn ($path) => in_array($path, $removeImages, true))
            ->values()
            ->all();

        $newFiles = $this->uploadedItemGalleryFiles($request);
        if (count($remainingGallery) + count($newFiles) > self::MAX_ITEM_GALLERY_IMAGES) {
            return back()
                ->withErrors(['images' => 'An item can have up to ' . self::MAX_ITEM_GALLERY_IMAGES . ' images.'])
                ->withInput();
        }

        $newImagePaths = $this->storeUploadedItemGallery($newFiles);
        $finalGallery = array_values(array_unique(array_merge($remainingGallery, $newImagePaths)));
        $imagePath = $finalGallery[0] ?? null;
        $removedPaths = array_values(array_diff($currentGallery, $finalGallery));

        try {
            DB::transaction(function () use ($item, $validated, $pricing, $finalGallery, $imagePath) {
                $item->update([
                    'barcode' => $validated['barcode'],
                    'design' => $validated['design'] ?? null,
                    'category' => $validated['category'],
                    'metal_type' => $pricing['metal_type'],
                    'sub_category' => $validated['sub_category'] ?? null,
                    'gross_weight' => $validated['gross_weight'],
                    'stone_weight' => $validated['stone_weight'] ?? 0,
                    'net_metal_weight' => $pricing['net_metal_weight'],
                    'purity' => $pricing['purity'],
                    'cost_price' => $pricing['cost_price'],
                    'selling_price' => $pricing['selling_price'],
                    'making_charges' => $validated['making_charges'] ?? 0,
                    'stone_charges' => $validated['stone_charges'] ?? 0,
                    'hallmark_charges' => $validated['hallmark_charges'] ?? 0,
                    'rhodium_charges' => $validated['rhodium_charges'] ?? 0,
                    'other_charges' => $validated['other_charges'] ?? 0,
                    'vendor_id' => $validated['vendor_id'] ?? null,
                    'karigar_id' => $validated['karigar_id'] ?? null,
                    'huid' => $validated['huid'] ?? null,
                    'hallmark_date' => $validated['hallmark_date'] ?? null,
                    'image' => $imagePath,
                    'images' => $finalGallery ?: null,
                    'pricing_review_required' => false,
                    'pricing_review_notes' => null,
                ]);

                AuditLog::create([
                    'shop_id' => $item->shop_id,
                    'user_id' => auth()->id(),
                    'action' => 'item_updated',
                    'model_type' => 'item',
                    'model_id' => $item->id,
                    'data' => array_merge(
                        collect($validated)->except(['image', 'images', 'remove_image', 'remove_images'])->all(),
                        ['image_count' => count($finalGallery)]
                    ),
                ]);
            });
        } catch (\Throwable $e) {
            $this->deleteItemGalleryFiles($newImagePaths);
            throw $e;
        }

        $this->deleteItemGalleryFiles($removedPaths);

        return redirect()->route('inventory.items.show', $item)
            ->with('success', 'Item updated successfully.');
    }

    private function uploadedItemGalleryFiles(Request $request): array
    {
        $files = $request->file('images', []);

        if ($files instanceof \Illuminate\Http\UploadedFile) {
            $files = [$files];
        }

        $files = is_array($files) ? array_values(array_filter($files)) : [];

        if ($files === [] && $request->hasFile('image')) {
            $files = [$request->file('image')];
        }

        return $files;
    }

    private function storeUploadedItemGallery(array $files): array
    {
        return collect($files)
            ->take(self::MAX_ITEM_GALLERY_IMAGES)
            ->map(fn (\Illuminate\Http\UploadedFile $file) => $file->store('items', 'public'))
            ->values()
            ->all();
    }

    private function deleteItemGalleryFiles(array $paths): void
    {
        collect($paths)
            ->map(fn ($path) => is_string($path) ? trim($path) : null)
            ->filter()
            ->unique()
            ->each(function (string $path): void {
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            });
    }

    /**
     * Quick-add a custom purity profile from the item create/edit form.
     * Creates the profile, derives today's rate immediately, and returns
     * the new option data as JSON so the form can inject it without a page reload.
     */
    public function quickAddPurity(Request $request)
    {
        $shop = auth()->user()->shop;
        abort_unless($shop && $shop->isRetailer(), 404);

        $validated = $request->validate([
            'metal_type'  => ['required', Rule::in(MetalRegistry::enabledMetalsForShop((int) $shop->id))],
            'purity_value' => 'required|numeric|min:0.001|max:1000',
        ]);

        $metalType   = $validated['metal_type'];
        $purityValue = (float) $validated['purity_value'];

        // Purity profiles are a rate-derivation mechanism. Piece-price metals
        // (platinum, copper) are sold at a fixed price and never use purity
        // profiles, so reject them here with a clear message instead of letting
        // the generic gold/silver guard fire deeper in the pricing service.
        if (MetalRegistry::uxItemCreationDefault($metalType) !== 'rate_derived') {
            return response()->json([
                'error' => 'Custom purity profiles apply only to rate-priced metals like gold and silver.',
            ], 422);
        }

        // Validate range: gold ≤ 24 (karat), silver ≤ 1000 (millesimal)
        if ($metalType === 'gold' && $purityValue > 24) {
            return response()->json(['error' => 'Gold purity cannot exceed 24K.'], 422);
        }
        if ($metalType === 'silver' && $purityValue > 1000) {
            return response()->json(['error' => 'Silver purity cannot exceed 1000.'], 422);
        }

        try {
            $profile = $this->pricing->createObservedProfileIfMissing(
                (int) $shop->id,
                $metalType,
                $purityValue
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Derive today's rate for the new profile immediately so the form
        // can start calculating without the nightly reprice job.
        $ratePerGram = null;
        $dailyRate = $this->pricing->currentDailyRate($shop);
        if ($dailyRate) {
            $ratePerGram = $this->pricing->resolvedRateForToday($shop, $metalType, $purityValue);
        }

        $normalizedValue = $this->pricing->normalizePurityString($purityValue);

        return response()->json([
            'value'        => $normalizedValue,
            'label'        => $profile->label,
            'rate_per_gram' => $ratePerGram,
        ]);
    }

    public function quickAddCategory(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        abort_unless($shopId, 403);

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->where('shop_id', $shopId),
            ],
        ]);

        $category = Category::create([
            'shop_id' => $shopId,
            'name'    => $validated['name'],
        ]);

        return response()->json([
            'id'   => $category->id,
            'name' => $category->name,
        ]);
    }

    public function quickAddSubCategory(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        abort_unless($shopId, 403);

        $validated = $request->validate([
            'category_id' => [
                'required',
                Rule::exists('categories', 'id')->where('shop_id', $shopId),
            ],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('sub_categories')
                    ->where('shop_id', $shopId)
                    ->where('category_id', $request->input('category_id')),
            ],
        ]);

        $sub = SubCategory::create([
            'category_id' => $validated['category_id'],
            'name'        => $validated['name'],
        ]);

        return response()->json([
            'name' => $sub->name,
        ]);
    }

    public function quickAddKarigar(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        abort_unless($shopId, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $karigar = \App\Models\Karigar::create([
            'shop_id'   => $shopId,
            'name'      => $validated['name'],
        ]);

        return response()->json([
            'id'   => $karigar->id,
            'name' => $karigar->name,
        ]);
    }

    /**
     * Build the metal picker data for retailer item forms.
     *
     * Returns the list of metals the shop offers (Tier 1 always; Tier 2 only
     * when opted in) plus a map of each metal's UX pricing mode so the form
     * can switch between rate-derived (gold/silver) and piece-price
     * (platinum/copper) entry.
     *
     * @return array{0: list<string>, 1: array<string,string>}
     */
    private function buildMetalPickerData(int $shopId): array
    {
        $enabledMetals = array_values(array_filter(
            MetalRegistry::allSupportedMetals(),
            fn (string $metal) => MetalRegistry::uxItemPickerVisible($metal, $shopId)
        ));

        $metalUxModes = [];
        foreach ($enabledMetals as $metal) {
            $metalUxModes[$metal] = MetalRegistry::uxItemCreationDefault($metal);
        }

        return [$enabledMetals, $metalUxModes];
    }

    private function buildRetailerResolvedRateMap($shop, $purityProfiles): array
    {
        $resolvedRates = [];

        foreach ($purityProfiles as $metalType => $profiles) {
            foreach ($profiles as $profile) {
                $resolvedRates[$metalType][$this->pricing->normalizePurityString((float) $profile->purity_value)] = [
                    'label' => $profile->label,
                    'rate_per_gram' => $this->pricing->resolvedRateForToday(
                        $shop,
                        $metalType,
                        (float) $profile->purity_value
                    ),
                ];
            }
        }

        return $resolvedRates;
    }

    /**
     * Delete an item (only if in_stock)
     */
    public function destroy(Item $item)
    {
        $this->authorize('delete', $item);

        $shopId = auth()->user()->shop_id;
        $barcode = $item->barcode;
        $shop = auth()->user()->shop;

        try {
            DB::transaction(function () use ($item, $shopId, $barcode, $shop) {
                // Re-fetch with a row lock so concurrent deletes can't both double-credit the lot
                $item = Item::where('shop_id', $shopId)->where('id', $item->id)->lockForUpdate()->firstOrFail();

                if ($item->status !== 'in_stock') {
                    throw new \LogicException('Only items in stock can be deleted.');
                }

                $fineGoldUsed = 0;

                if ($shop->isManufacturer() && $item->metal_lot_id) {
                    $lot = MetalLot::where('shop_id', $shopId)->where('id', $item->metal_lot_id)->lockForUpdate()->first();
                    if ($lot) {
                        $fineGoldUsed = $item->net_metal_weight * ($item->purity / 24) + ($item->wastage ?? 0);
                        $lot->fine_weight_remaining += $fineGoldUsed;
                        $lot->save();

                        MetalMovement::record([
                            'shop_id' => $shopId,
                            'from_lot_id' => null,
                            'to_lot_id' => $lot->id,
                            'fine_weight' => $fineGoldUsed,
                            'type' => 'item_deleted',
                            'reference_type' => 'item',
                            'reference_id' => $item->id,
                            'user_id' => auth()->id(),
                        ]);
                    }
                }

                AuditLog::create([
                    'shop_id' => $shopId,
                    'user_id' => auth()->id(),
                    'action' => 'item_deleted',
                    'model_type' => 'item',
                    'model_id' => $item->id,
                    'data' => [
                        'barcode' => $barcode,
                        'gold_returned' => $fineGoldUsed,
                    ],
                ]);

                $item->delete();
            });
        } catch (\LogicException $e) {
            return $this->dynamicRedirect('inventory.items.show', [$item], $e->getMessage(), 'error');
        }

        $msg = $shop->isRetailer()
            ? "Item {$barcode} deleted from stock."
            : "Item {$barcode} deleted and gold returned to lot.";

        return $this->dynamicRedirect('inventory.items.index', [], $msg);
    }

}
