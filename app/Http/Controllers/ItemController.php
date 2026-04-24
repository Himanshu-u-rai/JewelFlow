<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Models\Category;
use App\Models\Vendor;
use App\Models\AuditLog;
use App\Services\CatalogShareService;
use App\Services\ItemManufacturingService;
use App\Services\RetailerReportService;
use App\Services\ShopPricingService;
use App\Http\Concerns\RespondsDynamically;
use App\Support\ShopEdition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ItemController extends Controller
{
    use RespondsDynamically;

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
        if ($isRetailer) {
            $reportService = app(RetailerReportService::class);
            $stockAgingData = $reportService->stockAging();

            $period = $request->input('seller_period', '30');
            $sellersData = [
                'best' => $reportService->bestSellers(10, $period),
                'worst' => $reportService->worstSellers(10, $period),
                'period' => $period,
            ];
        }

        return view('inventory.items.index', compact('items', 'stats', 'categories', 'stockAgingData', 'sellersData', 'isRetailer', 'statusFilter'));
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
            $purityProfiles = $this->pricing->activePurityProfiles($shop)->groupBy('metal_type');
            $resolvedRates = $this->buildRetailerResolvedRateMap($shop, $purityProfiles);

            return view('inventory.items.create-retailer', compact(
                'categories',
                'vendors',
                'purityProfiles',
                'resolvedRates'
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
            'metal_type' => ['required', Rule::in(['gold', 'silver'])],
            'sub_category' => 'nullable|string|max:255',
            'gross_weight' => 'required|numeric|min:0.001',
            'stone_weight' => 'nullable|numeric|min:0',
            'purity' => 'required|numeric|min:0.001|max:1000',
            'cost_price' => 'nullable|numeric|min:0',
            'making_charges' => 'nullable|numeric|min:0',
            'stone_charges' => 'nullable|numeric|min:0',
            'hallmark_charges' => 'nullable|numeric|min:0',
            'rhodium_charges' => 'nullable|numeric|min:0',
            'other_charges' => 'nullable|numeric|min:0',
            'vendor_id' => ['nullable', Rule::exists('vendors', 'id')->where('shop_id', $shopId)],
            'huid' => ['nullable', 'string', 'max:30', Rule::unique('items', 'huid')->where('shop_id', $shopId)->whereNotNull('huid')],
            'hallmark_date' => 'nullable|date',
            'image' => 'nullable|file|mimes:jpeg,png,gif,webp|max:5120',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('items', 'public');
        }

        try {
            $pricing = $this->pricing->computeRetailerCostPayload(auth()->user()->shop, $validated);
        } catch (\Throwable $e) {
            return back()->withErrors(['pricing' => $e->getMessage()])->withInput();
        }

        $item = DB::transaction(function () use ($shopId, $validated, $pricing, $imagePath) {
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
                'huid' => $validated['huid'] ?? null,
                'hallmark_date' => $validated['hallmark_date'] ?? null,
                'source' => 'purchased',
                'status' => 'in_stock',
                'image' => $imagePath,
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
        $lot  = $shop->isManufacturer() ? MetalLot::find($item->metal_lot_id) : null;

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
            $purityProfiles = $this->pricing->activePurityProfiles($shop)->groupBy('metal_type');
            $resolvedRates = $this->buildRetailerResolvedRateMap($shop, $purityProfiles);

            return view('inventory.items.edit-retailer', compact(
                'item',
                'categories',
                'vendors',
                'purityProfiles',
                'resolvedRates'
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
        $lot = MetalLot::find($item->metal_lot_id);
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
            'data' => array_merge($validated, [
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
            'metal_type' => ['required', Rule::in(['gold', 'silver'])],
            'sub_category' => 'nullable|string|max:255',
            'gross_weight' => 'required|numeric|min:0.001',
            'stone_weight' => 'nullable|numeric|min:0',
            'purity' => 'required|numeric|min:0.001|max:1000',
            'cost_price' => 'nullable|numeric|min:0',
            'making_charges' => 'nullable|numeric|min:0',
            'stone_charges' => 'nullable|numeric|min:0',
            'hallmark_charges' => 'nullable|numeric|min:0',
            'rhodium_charges' => 'nullable|numeric|min:0',
            'other_charges' => 'nullable|numeric|min:0',
            'vendor_id' => ['nullable', Rule::exists('vendors', 'id')->where('shop_id', $shopId)],
            'huid' => ['nullable', 'string', 'max:30', Rule::unique('items', 'huid')->where('shop_id', $shopId)->whereNotNull('huid')->ignore($item->id)],
            'hallmark_date' => 'nullable|date',
            'image' => 'nullable|file|mimes:jpeg,png,gif,webp|max:5120',
            'remove_image' => 'nullable|boolean',
        ]);

        $imagePath = $item->image;
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

        try {
            $pricing = $this->pricing->computeRetailerCostPayload(auth()->user()->shop, $validated);
        } catch (\Throwable $e) {
            return back()->withErrors(['pricing' => $e->getMessage()])->withInput();
        }

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
            'huid' => $validated['huid'] ?? null,
            'hallmark_date' => $validated['hallmark_date'] ?? null,
            'image' => $imagePath,
            'pricing_review_required' => false,
            'pricing_review_notes' => null,
        ]);

        AuditLog::create([
            'shop_id' => $item->shop_id,
            'user_id' => auth()->id(),
            'action' => 'item_updated',
            'model_type' => 'item',
            'model_id' => $item->id,
            'data' => $validated,
        ]);

        return redirect()->route('inventory.items.show', $item)
            ->with('success', 'Item updated successfully.');
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

        // Only allow deleting in_stock items
        if ($item->status !== 'in_stock') {
            return $this->dynamicRedirect('inventory.items.show', [$item], 'Only items in stock can be deleted.', 'error');
        }

        $shopId = auth()->user()->shop_id;
        $barcode = $item->barcode;
        $shop = auth()->user()->shop;

        DB::transaction(function () use ($item, $shopId, $barcode, $shop) {
            $fineGoldUsed = 0;

            // For manufacturer items with a lot, return gold to lot
            if ($shop->isManufacturer() && $item->metal_lot_id) {
                $lot = MetalLot::find($item->metal_lot_id);
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

        $msg = $shop->isRetailer()
            ? "Item {$barcode} deleted from stock."
            : "Item {$barcode} deleted and gold returned to lot.";

        return $this->dynamicRedirect('inventory.items.index', [], $msg);
    }

}
