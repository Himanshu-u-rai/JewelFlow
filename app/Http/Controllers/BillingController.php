<?php

namespace App\Http\Controllers;

use App\Models\Platform\PlatformInvoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function index(): View
    {
        $shop     = auth()->user()->shop;
        $invoices = PlatformInvoice::where('shop_id', $shop->id)
            ->with('plan')
            ->latest('issued_at')
            ->paginate(20)
            ->withQueryString();

        return view('billing.index', compact('invoices'));
    }

    public function show(PlatformInvoice $invoice): View
    {
        abort_unless($invoice->shop_id === auth()->user()->shop_id, 403);

        $invoice->load(['plan', 'shop']);
        $shop = $invoice->shop;

        return view('billing.show', compact('invoice', 'shop'));
    }
}
