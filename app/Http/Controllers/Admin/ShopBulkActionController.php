<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AssignPlanToShop;
use App\Jobs\SendBulkShopEmail;
use App\Models\Shop;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopBulkActionController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action'        => ['required', 'string', 'in:suspend,unsuspend,assign_plan,send_email'],
            'shop_ids'      => ['required', 'array', 'min:1', 'max:50'],
            'shop_ids.*'    => ['required', 'integer', 'min:1'],
            'plan_id'       => ['required_if:action,assign_plan', 'nullable', 'integer', 'exists:plans,id'],
            'email_subject' => ['required_if:action,send_email', 'nullable', 'string', 'max:255'],
            'email_body'    => ['required_if:action,send_email', 'nullable', 'string', 'max:5000'],
        ]);

        $shopIds = array_map('intval', $validated['shop_ids']);
        $admin   = auth('platform_admin')->user();
        $adminId = $admin?->id ?? 0;
        $action  = $validated['action'];

        switch ($action) {
            case 'suspend':
                DB::table('shops')
                    ->whereIn('id', $shopIds)
                    ->update([
                        'access_mode'  => 'suspended',
                        'is_active'    => '0',
                        'suspended_at' => now(),
                        'suspended_by' => $adminId,
                        'updated_at'   => now(),
                    ]);

                $this->audit->log(
                    $admin,
                    'shop.bulk_suspend',
                    Shop::class,
                    null,
                    null,
                    ['shop_ids' => $shopIds, 'access_mode' => 'suspended'],
                    'Bulk suspend',
                    $request
                );

                return back()->with('success', count($shopIds) . ' shop(s) suspended.');

            case 'unsuspend':
                DB::table('shops')
                    ->whereIn('id', $shopIds)
                    ->update([
                        'access_mode'        => 'active',
                        'is_active'          => true,
                        'suspended_at'       => null,
                        'suspended_by'       => null,
                        'suspension_reason'  => null,
                        'suspended_until'    => null,
                        'deactivated_at'     => null,
                        'updated_at'         => now(),
                    ]);

                $this->audit->log(
                    $admin,
                    'shop.bulk_unsuspend',
                    Shop::class,
                    null,
                    null,
                    ['shop_ids' => $shopIds, 'access_mode' => 'active'],
                    'Bulk unsuspend',
                    $request
                );

                return back()->with('success', count($shopIds) . ' shop(s) unsuspended.');

            case 'assign_plan':
                $planId = (int) $validated['plan_id'];
                foreach ($shopIds as $shopId) {
                    AssignPlanToShop::dispatch($shopId, $planId, $adminId);
                }

                $this->audit->log(
                    $admin,
                    'shop.bulk_assign_plan',
                    Shop::class,
                    null,
                    null,
                    ['shop_ids' => $shopIds, 'plan_id' => $planId],
                    'Bulk plan assignment',
                    $request
                );

                return back()->with('success', 'Plan assignment queued for ' . count($shopIds) . ' shop(s).');

            case 'send_email':
                $subject = $validated['email_subject'];
                $body    = $validated['email_body'];
                foreach ($shopIds as $shopId) {
                    SendBulkShopEmail::dispatch($shopId, $subject, $body, $adminId);
                }

                $this->audit->log(
                    $admin,
                    'shop.bulk_send_email',
                    Shop::class,
                    null,
                    null,
                    ['shop_ids' => $shopIds, 'email_subject' => $subject],
                    'Bulk email send',
                    $request
                );

                return back()->with('success', 'Email queued for ' . count($shopIds) . ' shop(s).');
        }

        return back()->withErrors(['action' => 'Unknown action.']);
    }
}
