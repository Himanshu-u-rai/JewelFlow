<?php

namespace App\Http\Controllers;

use App\Models\Platform\PlatformInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class BillingController extends Controller
{
    /**
     * Legacy entry point — billing history is now merged into the Subscription
     * page (see SubscriptionController::status). Redirect any direct hits or
     * old bookmarks to the unified page.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('subscription.status');
    }

    public function show(PlatformInvoice $invoice): View
    {
        abort_unless($invoice->shop_id === auth()->user()->shop_id, 403);

        $invoice->load(['plan', 'shop']);
        $shop = $invoice->shop;

        return view('billing.show', compact('invoice', 'shop'));
    }
}
