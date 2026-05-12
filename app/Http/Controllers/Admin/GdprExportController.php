<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GdprExportController extends Controller
{
    public function __construct(private PlatformAuditService $audit) {}

    public function export(Request $request, Shop $shop): RedirectResponse
    {
        $actor = auth('platform_admin')->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        // Build export data synchronously for now (small shops); for large shops this should be a queued job
        $data = [
            'exported_at' => now()->toIso8601String(),
            'shop'        => $shop->only(['id', 'name', 'shop_type', 'owner_name', 'owner_mobile', 'gst_number', 'created_at']),
            'customers'   => DB::table('customers')->where('shop_id', $shop->id)
                ->select(['id', 'first_name', 'last_name', 'mobile', 'email', 'pan', 'id_number', 'address', 'city', 'created_at'])
                ->get()->toArray(),
            'invoices'    => DB::table('invoices')->where('shop_id', $shop->id)
                ->select(['id', 'invoice_number', 'customer_id', 'total_amount', 'payment_status', 'created_at'])
                ->limit(10000)->get()->toArray(),
            'users'       => DB::table('users')->where('shop_id', $shop->id)
                ->select(['id', 'name', 'mobile_number', 'role', 'created_at'])
                ->get()->toArray(),
        ];

        $json     = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $filename = 'gdpr-export-shop-' . $shop->id . '-' . now()->format('Y-m-d') . '.json';

        $this->audit->log(
            $actor,
            'shop.gdpr_export',
            Shop::class,
            $shop->id,
            null,
            ['exported_at' => now()->toIso8601String(), 'reason' => $validated['reason']],
            $validated['reason'],
            $request
        );

        return response()->streamDownload(
            fn () => print($json),
            $filename,
            ['Content-Type' => 'application/json']
        );
    }

    public function scheduleDelete(Request $request, Shop $shop): RedirectResponse
    {
        $actor = auth('platform_admin')->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        // Mark for deletion — a future scheduled command processes shops with pending_deletion status
        $before = $shop->only(['access_mode', 'is_active']);

        $shop->update([
            'access_mode'      => 'suspended',
            'is_active'        => false,
            'suspension_reason' => 'GDPR deletion scheduled: ' . $validated['reason'],
            'suspended_at'     => now(),
            'suspended_by'     => $actor->id,
        ]);

        // Record a platform_setting marker so a future cleanup command can find these shops
        DB::table('platform_settings')->upsert([
            [
                'key'   => 'gdpr_pending_delete_shop_' . $shop->id,
                'value' => json_encode(['requested_at' => now()->toIso8601String(), 'admin_id' => $actor->id, 'reason' => $validated['reason']]),
            ]
        ], ['key'], ['value']);

        $this->audit->log(
            $actor,
            'shop.gdpr_deletion_scheduled',
            Shop::class,
            $shop->id,
            $before,
            ['access_mode' => 'suspended', 'gdpr_pending' => true],
            $validated['reason'],
            $request
        );

        return back()->with('success', 'Shop suspended and marked for GDPR deletion. Data will be anonymised after the 30-day grace period.');
    }
}
