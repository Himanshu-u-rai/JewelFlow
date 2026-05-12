<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ComplianceAlert;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ComplianceAlertAdminController extends Controller
{
    public function index(Request $request): View
    {
        $query = ComplianceAlert::withoutGlobalScope('shop')
            ->with([
                'customer' => fn ($q) => $q->withoutGlobalScope('shop'),
            ]);

        // Join shops for shop name
        $query->leftJoin('shops', 'shops.id', '=', 'compliance_alerts.shop_id')
              ->select('compliance_alerts.*', 'shops.name as shop_name');

        // Filter: resolved status (default: unresolved)
        if ($request->filled('resolved')) {
            $sql = filter_var($request->resolved, FILTER_VALIDATE_BOOLEAN) ? 'IS TRUE' : 'IS FALSE';
            $query->whereRaw("compliance_alerts.resolved {$sql}");
        } else {
            $query->whereRaw('compliance_alerts.resolved IS FALSE');
        }

        if ($request->filled('alert_type')) {
            $query->where('compliance_alerts.alert_type', $request->alert_type);
        }

        if ($request->filled('shop_id')) {
            $query->where('compliance_alerts.shop_id', (int) $request->shop_id);
        }

        if ($request->filled('from')) {
            $query->whereDate('compliance_alerts.created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('compliance_alerts.created_at', '<=', $request->to);
        }

        $alerts = $query->orderByDesc('compliance_alerts.created_at')->paginate(25)->withQueryString();

        return view('super-admin.compliance.alerts', compact('alerts'));
    }
}
