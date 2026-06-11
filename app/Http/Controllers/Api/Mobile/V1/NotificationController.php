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
 * Returns raw data; the mobile.envelope middleware wraps it into
 * { data, meta, errors } and derives meta.pagination from the paginator.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'counter_type' => 'nullable|in:pos,quick_bill',
        ]);

        $paginator = ShopNotification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->when(
                ! empty($validated['counter_type']),
                fn ($q) => $q->where('counter_type', $validated['counter_type'])
            )
            ->latest('id')
            ->paginate(20);

        $paginator->getCollection()->transform(fn (ShopNotification $n) => [
            'id'            => (int) $n->id,
            'counter_type'  => $n->counter_type,
            'actor_name'    => $n->actor_name,
            'amount'        => (float) $n->amount,
            'customer_name' => $n->customer_name,
            'invoice_id'    => (int) $n->invoice_id,
            'invoice_type'  => $n->invoice_type,
            'read_at'       => optional($n->read_at)->toIso8601String(),
            'created_at'    => optional($n->created_at)->toIso8601String(),
        ]);

        return response()->json($paginator);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = ShopNotification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $note = ShopNotification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->find($id);

        if (! $note) {
            return response()->json([
                'errors' => [['code' => 'not_found', 'message' => 'Notification not found.']],
            ], 404);
        }

        if ($note->read_at === null) {
            $note->update(['read_at' => now()]);
        }

        return response()->json([
            'id'      => (int) $note->id,
            'read_at' => optional($note->read_at)->toIso8601String(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $marked = ShopNotification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['marked' => (int) $marked]);
    }
}
