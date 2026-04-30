<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Models\StockPurchase;
use App\Models\StockPurchaseItem;
use App\Models\Vendor;
use App\Services\BusinessIdentifierService;
use App\Services\ShopPricingService;
use App\Services\StockPurchaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use LogicException;

class StockPurchaseController extends Controller
{
    public function __construct(
        private readonly ShopPricingService $pricing,
        private readonly StockPurchaseService $purchaseService,
    ) {}

    public function index(Request $request)
    {
        $shop   = auth()->user()->shop;
        $shopId = $shop->id;

        $query = StockPurchase::where('shop_id', $shopId)
            ->with('vendor')
            ->withCount('lines');

        if ($request->filled('status') && in_array($request->status, ['draft', 'confirmed', 'stocked'], true)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->filled('date_from')) {
            $query->where('purchase_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('purchase_date', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('purchase_number', 'ilike', "%{$search}%")
                  ->orWhere('invoice_number', 'ilike', "%{$search}%")
                  ->orWhere('supplier_name', 'ilike', "%{$search}%");
            });
        }

        $purchases = $query->orderByDesc('purchase_date')->orderByDesc('id')->paginate(20)->withQueryString();

        $stats = DB::table('stock_purchases')
            ->where('shop_id', $shopId)
            ->selectRaw("
                COUNT(*) FILTER (WHERE status = 'confirmed') as total_confirmed,
                COALESCE(SUM(total_amount) FILTER (WHERE status = 'confirmed' AND purchase_date >= date_trunc('month', CURRENT_DATE)), 0) as month_amount,
                COUNT(*) FILTER (WHERE status = 'draft') as drafts_pending
            ")
            ->first();

        $monthItems = StockPurchaseItem::query()
            ->join('stock_purchases', 'stock_purchases.id', '=', 'stock_purchase_items.stock_purchase_id')
            ->where('stock_purchases.shop_id', $shopId)
            ->where('stock_purchases.status', 'confirmed')
            ->whereRaw("stock_purchases.purchase_date >= date_trunc('month', CURRENT_DATE)")
            ->whereIn('stock_purchase_items.line_type', ['ornament', 'bullion_for_sale'])
            ->count();

        $vendors = Vendor::where('shop_id', $shopId)->active()->orderBy('name')->get();

        return view('inventory.purchases.index', compact('purchases', 'stats', 'monthItems', 'vendors'));
    }

    public function create()
    {
        $shop   = auth()->user()->shop;
        $shopId = $shop->id;

        $vendors          = Vendor::where('shop_id', $shopId)->active()->orderBy('name')->get();
        $categories       = Category::where('shop_id', $shopId)->orderBy('name')->get();
        $purityProfiles   = $this->pricing->activePurityProfiles($shop)->groupBy('metal_type');
        $resolvedRates    = $this->buildResolvedRateMap($shop, $purityProfiles);

        return view('inventory.purchases.create', compact('vendors', 'categories', 'purityProfiles', 'resolvedRates'));
    }

    public function store(Request $request)
    {
        $shop   = auth()->user()->shop;
        $shopId = $shop->id;

        $validated = $this->validatePurchaseRequest($request, $shopId);

        $purchase = DB::transaction(function () use ($validated, $shopId, $request) {
            $identifier = BusinessIdentifierService::nextPurchaseIdentifier($shopId);

            // Create a new vendor from supplier details if requested
            if ($request->boolean('save_as_vendor') && ! empty($validated['supplier_name'])) {
                $vendor = Vendor::create([
                    'shop_id'    => $shopId,
                    'name'       => $validated['supplier_name'],
                    'gst_number' => $validated['supplier_gstin'] ?? null,
                    'is_active'  => true,
                ]);
                $validated['vendor_id']     = $vendor->id;
                $validated['supplier_name'] = null;
            }

            $imagePath = null;
            if ($request->hasFile('invoice_image')) {
                $imagePath = $request->file('invoice_image')->store('purchases', 'public');
            }

            $purchase = StockPurchase::create([
                'shop_id'               => $shopId,
                'vendor_id'             => $validated['vendor_id'] ?? null,
                'supplier_name'         => $validated['supplier_name'] ?? null,
                'supplier_gstin'        => $validated['supplier_gstin'] ?? null,
                'purchase_number'       => $identifier['number'],
                'invoice_number'        => $validated['invoice_number'] ?? null,
                'invoice_date'          => $validated['invoice_date'] ?? null,
                'purchase_date'         => $validated['purchase_date'],
                'status'                => 'draft',
                'invoice_image'         => $imagePath,
                'notes'                 => $validated['notes'] ?? null,
                'labour_discount'       => $validated['labour_discount'] ?? 0,
                'cgst_rate'             => $validated['cgst_rate'] ?? 0,
                'sgst_rate'             => $validated['sgst_rate'] ?? 0,
                'igst_rate'             => $validated['igst_rate'] ?? 0,
                'tcs_amount'            => $validated['tcs_amount'] ?? 0,
                'irn_number'            => $validated['irn_number'] ?? null,
                'ack_number'            => $validated['ack_number'] ?? null,
                'entered_by_user_id'    => auth()->id(),
            ]);

            $this->syncLines($purchase, $validated['lines'] ?? []);
            $this->recalculateTotals($purchase);

            return $purchase;
        });

        return redirect()->route('inventory.purchases.show', $purchase)
            ->with('success', "Purchase {$purchase->purchase_number} saved as draft.");
    }

    public function show(StockPurchase $purchase)
    {
        $this->authorizeShop($purchase);

        $purchase->load(['lines.item', 'lines.metalLot', 'vendor', 'enteredBy', 'confirmedBy']);

        return view('inventory.purchases.show', compact('purchase'));
    }

    public function vaultLineForm(StockPurchase $purchase, StockPurchaseItem $line)
    {
        $this->authorizeShop($purchase);
        abort_unless($line->stock_purchase_id === $purchase->id, 404);
        abort_unless($line->line_type === 'bullion_reserve', 422);
        abort_unless($line->metal_lot_id === null, 422);
        abort_unless(! $purchase->isDraft(), 422);

        $existingLots = MetalLot::query()
            ->where('shop_id', $purchase->shop_id)
            ->where('metal_type', $line->metal_type)
            ->orderByDesc('id')
            ->get(['id', 'lot_number', 'purity', 'fine_weight_remaining', 'metal_type']);

        return view('inventory.purchases.vault-line', compact('purchase', 'line', 'existingLots'));
    }

    public function vaultLine(StockPurchase $purchase, StockPurchaseItem $line, Request $request)
    {
        $this->authorizeShop($purchase);
        abort_unless($line->stock_purchase_id === $purchase->id, 404);
        abort_unless($line->line_type === 'bullion_reserve', 422);
        abort_unless($line->metal_lot_id === null, 422);
        abort_unless(! $purchase->isDraft(), 422);

        $shopId = $purchase->shop_id;

        $validated = $request->validate([
            'vault_action'  => 'required|in:new_lot,existing_lot',
            'metal_lot_id'  => 'required_if:vault_action,existing_lot|nullable|integer',
            'notes'         => 'nullable|string|max:500',
        ]);

        if ($validated['vault_action'] === 'existing_lot') {
            $targetLot = MetalLot::where('shop_id', $shopId)
                ->where('metal_type', $line->metal_type)
                ->findOrFail($validated['metal_lot_id']);
        }

        $metalType  = $line->metal_type;
        $purity     = (float) $line->purity;
        $gross      = (float) $line->gross_weight;
        $fineWeight = $metalType === 'silver'
            ? round($gross * ($purity / 1000), 6)
            : round($gross * ($purity / 24), 6);
        $totalCost      = (float) $line->purchase_line_amount;
        $costPerFineGram = $fineWeight > 0 ? round($totalCost / $fineWeight, 2) : 0;
        $userId = (int) auth()->id();

        $targetLot = $targetLot ?? null;

        DB::transaction(function () use (
            $purchase, $line, $validated, $shopId, $userId,
            $metalType, $purity, $fineWeight, $totalCost, $costPerFineGram,
            $targetLot
        ) {
            // Re-acquire line with row lock to prevent concurrent vault requests.
            $line = StockPurchaseItem::where('id', $line->id)->lockForUpdate()->firstOrFail();
            abort_if($line->metal_lot_id !== null, 422, 'Already vaulted by a concurrent request.');

            if ($validated['vault_action'] === 'new_lot') {
                $lot = MetalLot::create([
                    'shop_id'                => $shopId,
                    'vendor_id'              => $purchase->vendor_id,
                    'metal_type'             => $metalType,
                    'source'                 => 'purchase',
                    'purity'                 => $purity,
                    'fine_weight_total'      => $fineWeight,
                    'fine_weight_remaining'  => $fineWeight,
                    'cost_per_fine_gram'     => $costPerFineGram,
                    'notes'                  => $validated['notes'] ?? null,
                ]);
            } else {
                // Re-fetch with a row lock inside the transaction — calling lockForUpdate()
                // on a model instance has no effect; the lock must be on the query builder.
                $lot = MetalLot::where('id', $targetLot->id)->lockForUpdate()->firstOrFail();
                $lot->fine_weight_total     += $fineWeight;
                $lot->fine_weight_remaining += $fineWeight;
                if ($lot->cost_per_fine_gram == 0 && $costPerFineGram > 0) {
                    $lot->cost_per_fine_gram = $costPerFineGram;
                }
                $lot->save();
            }

            MetalMovement::record([
                'shop_id'        => $shopId,
                'from_lot_id'    => null,
                'to_lot_id'      => $lot->id,
                'fine_weight'    => $fineWeight,
                'type'           => 'purchase',
                'reference_type' => 'stock_purchase',
                'reference_id'   => $purchase->id,
                'user_id'        => $userId,
            ]);

            $line->metal_lot_id = $lot->id;
            $line->save();

            AuditLog::create([
                'shop_id'    => $shopId,
                'user_id'    => $userId,
                'action'     => 'bullion_vaulted_from_purchase',
                'model_type' => 'stock_purchase_item',
                'model_id'   => $line->id,
                'data'       => [
                    'purchase_number' => $purchase->purchase_number,
                    'lot_number'      => $lot->lot_number,
                    'fine_weight'     => $fineWeight,
                    'action'          => $validated['vault_action'],
                ],
            ]);
        });

        $lotNumber = MetalLot::find($line->fresh()->metal_lot_id)?->lot_number ?? '?';

        return redirect()->route('inventory.purchases.show', $purchase)
            ->with('success', "Bullion added to vault → Lot #{$lotNumber} (" . number_format($fineWeight, 3) . 'g fine).');
    }

    public function edit(StockPurchase $purchase)
    {
        $this->authorizeShop($purchase);

        if (! $purchase->isDraft()) {
            return redirect()->route('inventory.purchases.show', $purchase)
                ->with('error', 'Only draft purchases can be edited.');
        }

        $shop   = auth()->user()->shop;
        $shopId = $shop->id;

        $purchase->load('lines');
        $vendors          = Vendor::where('shop_id', $shopId)->active()->orderBy('name')->get();
        $categories       = Category::where('shop_id', $shopId)->orderBy('name')->get();
        $purityProfiles   = $this->pricing->activePurityProfiles($shop)->groupBy('metal_type');
        $resolvedRates    = $this->buildResolvedRateMap($shop, $purityProfiles);

        return view('inventory.purchases.create', compact('purchase', 'vendors', 'categories', 'purityProfiles', 'resolvedRates'));
    }

    public function update(Request $request, StockPurchase $purchase)
    {
        $this->authorizeShop($purchase);

        if (! $purchase->isDraft()) {
            return redirect()->route('inventory.purchases.show', $purchase)
                ->with('error', 'Only draft purchases can be edited.');
        }

        $shopId    = auth()->user()->shop_id;
        $validated = $this->validatePurchaseRequest($request, $shopId);

        DB::transaction(function () use ($purchase, $validated, $request): void {
            if ($request->boolean('save_as_vendor') && ! empty($validated['supplier_name'])) {
                $vendor = Vendor::create([
                    'shop_id'    => $purchase->shop_id,
                    'name'       => $validated['supplier_name'],
                    'gst_number' => $validated['supplier_gstin'] ?? null,
                    'is_active'  => true,
                ]);
                $validated['vendor_id']     = $vendor->id;
                $validated['supplier_name'] = null;
            }

            if ($request->hasFile('invoice_image')) {
                if ($purchase->invoice_image) {
                    Storage::disk('public')->delete($purchase->invoice_image);
                }
                $purchase->invoice_image = $request->file('invoice_image')->store('purchases', 'public');
            }

            $purchase->fill([
                'vendor_id'       => $validated['vendor_id'] ?? null,
                'supplier_name'   => $validated['supplier_name'] ?? null,
                'supplier_gstin'  => $validated['supplier_gstin'] ?? null,
                'invoice_number'  => $validated['invoice_number'] ?? null,
                'invoice_date'    => $validated['invoice_date'] ?? null,
                'purchase_date'   => $validated['purchase_date'],
                'notes'           => $validated['notes'] ?? null,
                'labour_discount' => $validated['labour_discount'] ?? 0,
                'cgst_rate'       => $validated['cgst_rate'] ?? 0,
                'sgst_rate'       => $validated['sgst_rate'] ?? 0,
                'igst_rate'       => $validated['igst_rate'] ?? 0,
                'tcs_amount'      => $validated['tcs_amount'] ?? 0,
                'irn_number'      => $validated['irn_number'] ?? null,
                'ack_number'      => $validated['ack_number'] ?? null,
            ]);
            $purchase->save();

            $this->syncLines($purchase, $validated['lines'] ?? []);
            $this->recalculateTotals($purchase);
        });

        return redirect()->route('inventory.purchases.show', $purchase)
            ->with('success', 'Purchase updated successfully.');
    }

    public function confirm(StockPurchase $purchase)
    {
        $this->authorizeShop($purchase);

        try {
            $this->purchaseService->confirmPurchase($purchase, auth()->id());
        } catch (LogicException $e) {
            return redirect()->route('inventory.purchases.show', $purchase)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.purchases.show', $purchase)
            ->with('success', "Purchase {$purchase->purchase_number} confirmed. You can now add items to shop inventory.");
    }

    public function addToInventory(StockPurchase $purchase)
    {
        $this->authorizeShop($purchase);

        try {
            $itemCount = $this->purchaseService->addToInventory($purchase, auth()->id());
        } catch (LogicException $e) {
            return redirect()->route('inventory.purchases.show', $purchase)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.purchases.show', $purchase)
            ->with('success', "{$itemCount} item(s) from purchase {$purchase->purchase_number} added to shop inventory.");
    }

    public function destroy(StockPurchase $purchase)
    {
        $this->authorizeShop($purchase);

        try {
            $this->purchaseService->deletePurchase($purchase);
        } catch (LogicException $e) {
            return redirect()->route('inventory.purchases.show', $purchase)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.purchases.index')
            ->with('success', 'Draft purchase deleted.');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function authorizeShop(StockPurchase $purchase): void
    {
        if ($purchase->shop_id !== auth()->user()->shop_id) {
            abort(403);
        }
    }

    private function validatePurchaseRequest(Request $request, int $shopId): array
    {
        $validated = $request->validate([
            'vendor_id'       => ['nullable', Rule::exists('vendors', 'id')->where('shop_id', $shopId)],
            'supplier_name'   => 'nullable|string|max:255',
            'supplier_gstin'  => 'nullable|string|max:20',
            'invoice_number'  => 'nullable|string|max:100',
            'invoice_date'    => 'nullable|date',
            'purchase_date'   => 'required|date',
            'invoice_image'   => 'nullable|file|mimes:jpeg,png,pdf|max:10240',
            'notes'           => 'nullable|string|max:2000',
            'labour_discount' => 'nullable|numeric|min:0',
            'cgst_rate'       => 'nullable|numeric|min:0|max:100',
            'sgst_rate'       => 'nullable|numeric|min:0|max:100',
            'igst_rate'       => 'nullable|numeric|min:0|max:100',
            'tcs_amount'      => 'nullable|numeric|min:0',
            'irn_number'      => 'nullable|string|max:100',
            'ack_number'      => 'nullable|string|max:100',

            'lines'                          => 'nullable|array',
            'lines.*.id'                     => 'nullable|integer',
            'lines.*.line_type'              => ['required', Rule::in(['ornament', 'bullion_for_sale', 'bullion_reserve'])],
            'lines.*.design'                 => 'nullable|string|max:255',
            'lines.*.category'               => 'nullable|string|max:255',
            'lines.*.sub_category'           => 'nullable|string|max:255',
            'lines.*.metal_type'             => ['required', Rule::in(['gold', 'silver'])],
            'lines.*.purity'                 => 'required|numeric|min:0.001',
            'lines.*.gross_weight'           => 'required|numeric|min:0.001',
            'lines.*.stone_weight'           => 'nullable|numeric|min:0',
            'lines.*.huid'                   => 'nullable|string|max:30',
            'lines.*.hallmark_date'          => 'nullable|date',
            'lines.*.hsn_code'               => 'nullable|string|max:20',
            'lines.*.making_charges'         => 'nullable|numeric|min:0',
            'lines.*.stone_charges'          => 'nullable|numeric|min:0',
            'lines.*.hallmark_charges'       => 'nullable|numeric|min:0',
            'lines.*.rhodium_charges'        => 'nullable|numeric|min:0',
            'lines.*.other_charges'          => 'nullable|numeric|min:0',
            'lines.*.purchase_rate_per_gram' => 'nullable|numeric|min:0',
            'lines.*.purchase_line_amount'   => 'nullable|numeric|min:0',
            'lines.*.barcode'                => 'nullable|string|max:100',
            'lines.*.notes'                  => 'nullable|string|max:500',
        ]);

        $igst = (float) ($validated['igst_rate'] ?? 0);
        $cgst = (float) ($validated['cgst_rate'] ?? 0);
        $sgst = (float) ($validated['sgst_rate'] ?? 0);
        if ($igst > 0 && ($cgst > 0 || $sgst > 0)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'igst_rate' => 'Use either IGST (inter-state) or CGST + SGST (intra-state) — not both.',
            ]);
        }

        return $validated;
    }

    private function syncLines(StockPurchase $purchase, array $lines): void
    {
        $incomingIds = collect($lines)->pluck('id')->filter()->values();

        // Delete removed lines
        $purchase->lines()->whereNotIn('id', $incomingIds)->delete();

        foreach ($lines as $index => $lineData) {
            $netWeight = max(0, (float) ($lineData['gross_weight'] ?? 0) - (float) ($lineData['stone_weight'] ?? 0));

            $attributes = [
                'shop_id'                => $purchase->shop_id,
                'line_type'              => $lineData['line_type'],
                'design'                 => $lineData['design'] ?? null,
                'category'               => $lineData['category'] ?? null,
                'sub_category'           => $lineData['sub_category'] ?? null,
                'metal_type'             => $lineData['metal_type'],
                'purity'                 => $lineData['purity'],
                'gross_weight'           => $lineData['gross_weight'],
                'stone_weight'           => $lineData['stone_weight'] ?? 0,
                'net_metal_weight'       => $netWeight,
                'huid'                   => $lineData['huid'] ?? null,
                'hallmark_date'          => $lineData['hallmark_date'] ?? null,
                'hsn_code'               => $lineData['hsn_code'] ?? null,
                'making_charges'         => $lineData['making_charges'] ?? 0,
                'stone_charges'          => $lineData['stone_charges'] ?? 0,
                'hallmark_charges'       => $lineData['hallmark_charges'] ?? 0,
                'rhodium_charges'        => $lineData['rhodium_charges'] ?? 0,
                'other_charges'          => $lineData['other_charges'] ?? 0,
                'purchase_rate_per_gram' => $lineData['purchase_rate_per_gram'] ?? 0,
                'purchase_line_amount'   => round(
                    $netWeight * (float) ($lineData['purchase_rate_per_gram'] ?? 0)
                    + (float) ($lineData['making_charges']   ?? 0)
                    + (float) ($lineData['stone_charges']    ?? 0)
                    + (float) ($lineData['hallmark_charges'] ?? 0)
                    + (float) ($lineData['rhodium_charges']  ?? 0)
                    + (float) ($lineData['other_charges']    ?? 0),
                    2
                ),
                'barcode'                => $lineData['barcode'] ?? null,
                'notes'                  => $lineData['notes'] ?? null,
                'sort_order'             => $index,
            ];

            if (! empty($lineData['id'])) {
                $line = StockPurchaseItem::find($lineData['id']);
                if ($line && $line->stock_purchase_id === $purchase->id) {
                    $line->update($attributes);
                    continue;
                }
            }

            $purchase->lines()->create($attributes);
        }
    }

    private function recalculateTotals(StockPurchase $purchase): void
    {
        $purchase->refresh();
        $lineTotal = $purchase->lines->sum(fn ($l) => (float) $l->purchase_line_amount);
        $subtotal  = round($lineTotal - (float) $purchase->labour_discount, 2);

        $cgstAmount = round($subtotal * (float) $purchase->cgst_rate / 100, 2);
        $sgstAmount = round($subtotal * (float) $purchase->sgst_rate / 100, 2);
        $igstAmount = round($subtotal * (float) $purchase->igst_rate / 100, 2);
        $total      = round($subtotal + $cgstAmount + $sgstAmount + $igstAmount + (float) $purchase->tcs_amount, 2);

        $purchase->update([
            'subtotal_amount' => $subtotal,
            'cgst_amount'     => $cgstAmount,
            'sgst_amount'     => $sgstAmount,
            'igst_amount'     => $igstAmount,
            'total_amount'    => $total,
        ]);
    }

    private function buildResolvedRateMap($shop, $purityProfiles): array
    {
        $map = [];
        foreach ($purityProfiles as $metalType => $profiles) {
            foreach ($profiles as $profile) {
                $key = $this->pricing->normalizePurityString((float) $profile->purity_value);
                $map[$metalType][$key] = [
                    'label'         => $profile->label,
                    'rate_per_gram' => $this->pricing->resolvedRateForToday($shop, $metalType, (float) $profile->purity_value),
                ];
            }
        }
        return $map;
    }
}
