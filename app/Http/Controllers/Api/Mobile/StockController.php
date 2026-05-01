<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;
        $query = Item::query()->where('shop_id', $shopId);

        // Filter by status
        $allowedStatuses = ['in_stock', 'sold', 'returned'];
        if ($request->filled('status') && in_array($request->status, $allowedStatuses, true)) {
            $query->where('status', $request->status);
        } else {
            $query->where('status', 'in_stock');
        }

        // Filter by category
        if ($request->filled('category')) {
            $query->where('category', 'ilike', $request->category);
        }

        // Filter by purity
        $allowedPurities = [14, 18, 22, 24];
        if ($request->filled('purity') && in_array((int) $request->purity, $allowedPurities, true)) {
            $query->where('purity', (int) $request->purity);
        }

        // Search
        if ($request->filled('search')) {
            $search = mb_substr(trim((string) $request->search), 0, 100);
            $query->where(function ($q) use ($search) {
                $q->where('barcode', 'ilike', "%{$search}%")
                  ->orWhere('design', 'ilike', "%{$search}%")
                  ->orWhere('category', 'ilike', "%{$search}%");
            });
        }

        // Aged stock filter (default 90 days when enabled)
        if ($request->boolean('aged')) {
            $agedDays = max(1, (int) $request->input('aged_days', 90));
            $query->where('created_at', '<=', now()->subDays($agedDays));
        }

        // High-value threshold filter
        if ($request->filled('min_price')) {
            $minPrice = max(0, (float) $request->input('min_price'));
            $query->where('selling_price', '>=', $minPrice);
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $items = $query->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($items);
    }

    public function batchStatus(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $validated = $request->validate([
            'item_ids' => 'required|array|min:1|max:100',
            'item_ids.*' => 'integer',
            'status' => ['required', Rule::in(['in_stock', 'sold', 'returned'])],
        ]);

        $ids = collect($validated['item_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $items = Item::query()
            ->where('shop_id', $shopId)
            ->whereIn('id', $ids)
            ->get(['id', 'barcode', 'status']);

        if ($items->count() !== $ids->count()) {
            return response()->json(['message' => 'Some selected items were not found for this shop.'], 422);
        }

        Item::query()
            ->where('shop_id', $shopId)
            ->whereIn('id', $ids)
            ->update(['status' => $validated['status']]);

        AuditLog::create([
            'shop_id' => $shopId,
            'user_id' => (int) $request->user()->id,
            'action' => 'stock_batch_status_updated',
            'model_type' => 'item',
            'model_id' => 0,
            'description' => 'Batch status update from mobile stock screen.',
            'data' => [
                'source' => 'mobile_app',
                'status' => $validated['status'],
                'item_ids' => $ids->all(),
                'barcodes' => $items->pluck('barcode')->values()->all(),
            ],
        ]);

        return response()->json([
            'updated_count' => $ids->count(),
            'status' => $validated['status'],
            'message' => 'Stock status updated successfully.',
        ]);
    }
}
