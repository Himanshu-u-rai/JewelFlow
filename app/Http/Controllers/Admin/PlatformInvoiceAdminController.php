<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformInvoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformInvoiceAdminController extends Controller
{
    public function index(Request $request): View
    {
        $query = PlatformInvoice::query()->with(['shop', 'plan']);

        if ($request->filled('shop')) {
            $shopId = (int) $request->input('shop');
            $query->where('shop_id', $shopId);
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($inner) use ($q) {
                $inner->where('invoice_number', 'ilike', "%{$q}%")
                    ->orWhereHas('shop', fn ($s) => $s->where('name', 'ilike', "%{$q}%"));
            });
        }

        if ($request->filled('plan_id')) {
            $query->where('plan_id', (int) $request->input('plan_id'));
        }

        if ($request->filled('billing_cycle')) {
            $query->where('billing_cycle', $request->input('billing_cycle'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('issued_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('issued_at', '<=', $request->input('date_to'));
        }

        // Compute filtered totals before pagination
        $filteredQuery = clone $query;
        $totals = [
            'count'   => (clone $filteredQuery)->count(),
            'revenue' => (clone $filteredQuery)->where('status', 'issued')->sum('total_amount'),
        ];

        $invoices = $query->latest('issued_at')->paginate(25)->withQueryString();

        $plans = Plan::orderBy('name')->get(['id', 'name']);

        return view('super-admin.invoices.index', compact('invoices', 'plans', 'totals'));
    }
}
