<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformFraudFlag;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FraudFlagController extends Controller
{
    public function __construct(private PlatformAuditService $audit) {}

    public function index(Request $request): View
    {
        $query = PlatformFraudFlag::with('shop')
            ->orderByDesc('created_at');

        // Filter by reviewed status (default: unreviewed only)
        $reviewed = $request->input('reviewed', '0');
        if ($reviewed === '0') {
            $query->whereRaw('reviewed IS FALSE');
        } elseif ($reviewed === '1') {
            $query->whereRaw('reviewed IS TRUE');
        }
        // '2' = show all

        // Filter by flag type
        if ($request->filled('flag_type')) {
            $query->where('flag_type', $request->input('flag_type'));
        }

        $flags = $query->paginate(25)->withQueryString();

        return view('super-admin.security.fraud-flags', [
            'flags'    => $flags,
            'flagTypes' => [
                PlatformFraudFlag::TYPE_INVOICE_SPIKE      => 'Invoice Spike',
                PlatformFraudFlag::TYPE_BULK_CUSTOMERS     => 'Bulk Customers',
                PlatformFraudFlag::TYPE_CROSS_TENANT_PAN   => 'Cross-Tenant PAN',
                PlatformFraudFlag::TYPE_INACTIVE_SUBSCRIBER => 'Inactive Subscriber',
            ],
        ]);
    }

    public function markReviewed(Request $request, PlatformFraudFlag $flag): RedirectResponse
    {
        $request->validate([
            'review_notes' => ['required', 'string', 'min:5'],
        ]);

        /** @var PlatformAdmin $admin */
        $admin = auth('platform_admin')->user();

        $before = [
            'reviewed'      => $flag->reviewed,
            'reviewed_by'   => $flag->reviewed_by,
            'reviewed_at'   => $flag->reviewed_at?->toDateTimeString(),
            'review_notes'  => $flag->review_notes,
        ];

        $flag->update([
            'reviewed'     => true,
            'reviewed_by'  => $admin->id,
            'reviewed_at'  => now(),
            'review_notes' => $request->input('review_notes'),
        ]);

        $this->audit->log(
            $admin,
            'admin.fraud_flag_reviewed',
            PlatformFraudFlag::class,
            $flag->id,
            $before,
            [
                'reviewed'     => true,
                'reviewed_by'  => $admin->id,
                'reviewed_at'  => now()->toDateTimeString(),
                'review_notes' => $request->input('review_notes'),
            ],
            $request->input('review_notes'),
            $request
        );

        return back()->with('success', 'Fraud flag marked as reviewed.');
    }
}
