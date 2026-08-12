<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\SubscriptionEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Super-Admin read-only visibility into two money-safety event streams:
 *   • `payment.unresolved` — captured Razorpay payments that could not be
 *     applied to a subscription;
 *   • `refund.invalid`     — correctly-signed refund events refused by the
 *     webhook's fail-closed validation (no mutation, no alert, but audited).
 *
 * This is the "genuinely visible, not just stored" surface for the money-safety
 * audit trail: every refused capture/refund, its attempt count / first+last
 * failure, whether it is transient (auto-retrying) or permanent (needs a
 * refund), and whether a later reconcile already resolved it. The generic
 * Blade view renders `after` fields (payment_id, refund_id, attempt_count,
 * resolved_at, reason) the same way for both types. Read-only — owns no mutations.
 */
class UnresolvedPaymentController extends Controller
{
    public function index(Request $request): View
    {
        $query = SubscriptionEvent::query()
            ->whereIn('event_type', ['payment.unresolved', 'refund.invalid']);

        // Default view: still-open (unresolved) records. resolved_at lives in the
        // `after` JSON; in Postgres `after->>'resolved_at'` is text-or-null.
        $status = $request->input('status', 'open');
        if ($status === 'open') {
            $query->whereRaw("after->>'resolved_at' IS NULL");
        } elseif ($status === 'resolved') {
            $query->whereRaw("after->>'resolved_at' IS NOT NULL");
        }

        if ($request->filled('payment_id')) {
            $query->where('after->payment_id', $request->input('payment_id'));
        }

        $events = $query->orderByDesc('created_at')->paginate(25)->withQueryString();

        return view('super-admin.payments.unresolved', compact('events', 'status'));
    }
}
