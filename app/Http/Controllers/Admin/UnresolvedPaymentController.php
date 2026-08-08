<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\SubscriptionEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Super-Admin read-only visibility into captured Razorpay payments that could
 * not be applied to a subscription (payment.unresolved SubscriptionEvents).
 *
 * This is the "genuinely visible, not just stored" surface for the money-safety
 * audit trail: every unapplied capture, its attempt count / first+last failure,
 * whether it is transient (auto-retrying) or permanent (needs a refund), and
 * whether a later reconcile already resolved it. Read-only — owns no mutations.
 */
class UnresolvedPaymentController extends Controller
{
    public function index(Request $request): View
    {
        $query = SubscriptionEvent::query()
            ->where('event_type', 'payment.unresolved');

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
