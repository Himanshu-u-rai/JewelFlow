<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Models\ShopNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner-only sale notification feed (v1, enveloped).
 *
 * Scoping: every query is filtered by recipient_user_id = the caller, so one
 * owner never sees another owner's feed. BelongsToShop adds shop_id isolation,
 * and the route-level role:owner middleware blocks non-owners entirely.
 *
 * Responses are returned PRE-ENVELOPED ({ data, meta, errors }). The
 * mobile.envelope middleware passes these through (re-stamping base meta), so
 * the client receives meta.current_page / meta.last_page directly.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'counter_type' => 'sometimes|in:pos,quick_bill',
            'page'         => 'sometimes|integer|min:1',
        ]);

        $page = ShopNotification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->when(
                $request->filled('counter_type'),
                fn ($q) => $q->where('counter_type', $request->counter_type)
            )
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (ShopNotification $n) => [
                'id'            => (int) $n->id,
                'counter_type'  => $n->counter_type,
                'actor_name'    => $n->actor_name,
                'amount'        => (float) $n->amount,
                'customer_name' => $n->customer_name,
                'invoice_id'    => (int) $n->invoice_id,
                'invoice_type'  => $n->invoice_type,
                'read_at'       => optional($n->read_at)->toIso8601String(),
                'created_at'    => optional($n->created_at)->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
            'errors' => [],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = ShopNotification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['data' => ['count' => $count], 'meta' => [], 'errors' => []]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $note = ShopNotification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->findOrFail($id); // 404 if not owned by caller

        if (is_null($note->read_at)) {
            $note->update(['read_at' => now()]); // idempotent
        }

        return response()->json(['data' => ['id' => (int) $note->id], 'meta' => [], 'errors' => []]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        ShopNotification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['ok' => true], 'meta' => [], 'errors' => []]);
    }
}
