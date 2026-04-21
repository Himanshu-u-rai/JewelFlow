<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Data\Mobile\VendorData;
use App\Data\Mobile\VendorLedgerData;
use App\Data\Mobile\VendorLedgerEntryData;
use App\Data\Mobile\VendorLedgerSummaryData;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\DataCollection;

class VendorController extends Controller
{
    /**
     * Paginated, searchable, filterable list of vendors for the current shop.
     * Shop scoping is enforced automatically by the BelongsToShop global scope
     * on the Vendor model.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search'   => 'nullable|string|max:255',
            'status'   => 'nullable|in:active,inactive,all',
            'sort'     => 'nullable|in:recent,name,updated',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $status  = $validated['status'] ?? 'all';
        $sort    = $validated['sort'] ?? 'recent';

        $query = Vendor::query()
            ->withCount(['items' => fn ($q) => $q->where('status', 'in_stock')]);

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('contact_person', 'ilike', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('gst_number', 'ilike', "%{$search}%");
            });
        }

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'inactive') {
            $query->inactive();
        }

        match ($sort) {
            'name'    => $query->orderBy('name'),
            'updated' => $query->latest('updated_at'),
            default   => $query->latest(),
        };

        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())
            ->map(fn (Vendor $v) => VendorData::fromModel($v, (int) $v->items_count)->toArray())
            ->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Single vendor, shop-scoped via route model binding + global scope.
     */
    public function show(int $id): JsonResponse
    {
        $vendor = Vendor::withCount(['items' => fn ($q) => $q->where('status', 'in_stock')])
            ->findOrFail($id);

        return response()->json(VendorData::fromModel($vendor, (int) $vendor->items_count));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        if (isset($validated['gst_number'])) {
            $validated['gst_number'] = strtoupper(trim($validated['gst_number']));
        }

        $validated['is_active'] = $validated['is_active'] ?? true;

        $vendor = Vendor::create($validated);
        $vendor->loadCount(['items' => fn ($q) => $q->where('status', 'in_stock')]);

        return response()->json(
            VendorData::fromModel($vendor, (int) $vendor->items_count),
            201,
        );
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $vendor = Vendor::findOrFail($id);

        $validated = $this->validatePayload($request, $vendor->id);

        if (array_key_exists('gst_number', $validated) && $validated['gst_number'] !== null) {
            $validated['gst_number'] = strtoupper(trim($validated['gst_number']));
        }

        $vendor->update($validated);
        $vendor->loadCount(['items' => fn ($q) => $q->where('status', 'in_stock')]);

        return response()->json(VendorData::fromModel($vendor, (int) $vendor->items_count));
    }

    /**
     * Hard delete (no soft-delete column on vendors table). Mirrors web
     * behavior: refuses when the vendor still has linked items.
     */
    public function destroy(int $id): JsonResponse
    {
        $vendor = Vendor::findOrFail($id);

        if ($vendor->items()->exists()) {
            return response()->json([
                'message' => 'Cannot delete vendor with associated items.',
            ], 422);
        }

        $vendor->delete();

        return response()->json([
            'message' => 'Vendor deleted successfully.',
        ]);
    }

    /**
     * Vendor ledger for mobile.
     *
     * Current schema has no dedicated vendor_transactions / purchases /
     * vendor_payments table — the only vendor-linked data is `items`
     * (via items.vendor_id). So v1 ledger = one entry per item attributed
     * to this vendor, amount = cost_price, dated at item created_at.
     * Payments side does not exist yet; outstanding_balance = total_purchases.
     */
    public function ledger(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $vendor = Vendor::withCount(['items' => fn ($q) => $q->where('status', 'in_stock')])
            ->findOrFail($id);

        // Summary aggregates over ALL items for this vendor (not just page).
        $summaryRow = Item::query()
            ->where('vendor_id', $vendor->id)
            ->selectRaw("
                count(*) as total_items,
                count(*) filter (where status = 'in_stock') as active_items,
                count(*) filter (where status = 'sold') as sold_items,
                coalesce(sum(cost_price), 0) as total_cost_value,
                coalesce(sum(selling_price), 0) as total_selling_value
            ")
            ->first();

        $totalPurchases = (float) ($summaryRow->total_cost_value ?? 0);

        $summary = new VendorLedgerSummaryData(
            total_items: (int) ($summaryRow->total_items ?? 0),
            active_items: (int) ($summaryRow->active_items ?? 0),
            sold_items: (int) ($summaryRow->sold_items ?? 0),
            total_cost_value: $totalPurchases,
            total_selling_value: (float) ($summaryRow->total_selling_value ?? 0),
            total_purchases: $totalPurchases,
            total_payments: 0.0,
            outstanding_balance: $totalPurchases,
        );

        // Paginated ledger entries: one per item.
        $paginator = Item::query()
            ->where('vendor_id', $vendor->id)
            ->latest('created_at')
            ->paginate($perPage, [
                'id', 'barcode', 'category', 'sub_category', 'cost_price',
                'status', 'created_at', 'gross_weight', 'purity',
            ]);

        $entries = collect($paginator->items())->map(function (Item $item) {
            $desc = trim(sprintf(
                '%s — %s%s',
                $item->barcode,
                $item->category ?: 'item',
                $item->sub_category ? ' / ' . $item->sub_category : '',
            ));

            return new VendorLedgerEntryData(
                id: 'item-' . $item->id,
                date: optional($item->created_at)?->toIso8601String() ?? '',
                type: 'purchase',
                description: $desc,
                amount: (float) $item->cost_price,
                reference_id: (int) $item->id,
                reference_type: 'item',
                meta: sprintf(
                    '%s g @ %sK, status=%s',
                    rtrim(rtrim((string) $item->gross_weight, '0'), '.') ?: '0',
                    rtrim(rtrim((string) $item->purity, '0'), '.') ?: '0',
                    (string) $item->status,
                ),
            );
        })->all();

        $entriesCollection = VendorLedgerEntryData::collect($entries, DataCollection::class);

        $payload = new VendorLedgerData(
            vendor: VendorData::fromModel($vendor, (int) $vendor->items_count),
            summary: $summary,
            entries: $entriesCollection,
            current_page: $paginator->currentPage(),
            per_page: $paginator->perPage(),
            total: $paginator->total(),
            last_page: $paginator->lastPage(),
        );

        return response()->json($payload);
    }

    /**
     * Shared validation for store/update. Mirrors web `VendorController`
     * rules exactly; re-used here rather than extracted to a service
     * because the web controller keeps the rules inline too — pulling
     * them into a shared helper risks changing web behavior.
     */
    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name'           => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'mobile'         => ['nullable', 'string', 'max:15', 'regex:/^[0-9+\-\s()]{7,15}$/'],
            'email'          => 'nullable|email|max:255',
            'address'        => 'nullable|string|max:1000',
            'city'           => 'nullable|string|max:100',
            'state'          => 'nullable|string|max:100',
            'gst_number'     => [
                'nullable',
                'string',
                'size:15',
                'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/i',
            ],
            'notes'          => 'nullable|string|max:2000',
            'is_active'      => 'nullable|boolean',
        ], [
            'mobile.regex'     => 'Mobile number must be 7–15 digits and may include +, -, spaces, or parentheses.',
            'gst_number.size'  => 'GST number must be exactly 15 characters.',
            'gst_number.regex' => 'GST number format is invalid (e.g. 22AAAAA0000A1Z5).',
        ]);
    }
}
