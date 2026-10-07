<?php

namespace App\Http\Controllers;

use App\Services\ProductPromotionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductPromotionController extends Controller
{
    public function index(Request $request, ProductPromotionService $service)
    {
        $owner = $service->owner($request);
        $pending = $service->requests($owner)->whereNull('consumed_at')->where('expires_at', '>', now())
            ->get(['id', 'target_user_id', 'target_shop_id', 'expires_at']);
        // Counterparty name appears only after their authenticated approval. No contact details.
        foreach ($pending as $row) {
            $row->target_name = $row->target_user_id
                ? DB::table('shops')->where('id', $row->target_shop_id)->value('name') : null;
        }

        return response()->view('product-preferences', [
            'owner' => $owner, 'prefix' => $service->routePrefix($owner),
            'targetLabel' => $service->target($owner) === 'dhiran' ? 'Dhiran' : 'JewelFlows Retail',
            'preference' => $service->preference($owner), 'pending' => $pending,
            'incoming' => $service->incomingRequests($owner)->whereNull('consumed_at')->where('expires_at', '>', now())->get(['id']),
            'recognitions' => $service->recognitions($owner),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function preference(Request $request, ProductPromotionService $service)
    {
        $owner = $service->owner($request);
        $data = $request->validate(['choice' => ['required', 'in:already_use,opt_out']]);
        $service->choose($owner, $data['choice']);

        return redirect()->route($owner->realm === 'dhiran' ? 'dhiran.dashboard' : 'dashboard')
            ->with('status', 'Promotion preference saved.');
    }

    public function start(Request $request, ProductPromotionService $service)
    {
        $owner = $service->owner($request);
        $data = $request->validate(['password' => ['required', 'string', 'max:1024'], 'consent' => ['accepted']]);
        $code = $service->start($owner, $data['password']);

        return redirect()->route($service->routePrefix($owner).'.index')->with('recognition_code', $code);
    }

    public function approve(Request $request, ProductPromotionService $service)
    {
        $owner = $service->owner($request);
        $data = $request->validate([
            'password' => ['required', 'string', 'max:1024'], 'consent' => ['accepted'],
            'code' => ['required', 'string', 'regex:/\A[0-9a-fA-F]{40}\z/'],
        ]);
        $service->approve($owner, $data['password'], $data['code']);

        return redirect()->route($service->routePrefix($owner).'.index')
            ->with('status', 'Approval recorded. Return to the first product and confirm the business there within 10 minutes of creating the code.');
    }

    public function finish(Request $request, ProductPromotionService $service)
    {
        $owner = $service->owner($request);
        $data = $request->validate([
            'password' => ['required', 'string', 'max:1024'], 'consent' => ['accepted'], 'request_id' => ['required', 'uuid'],
        ]);
        $service->finish($owner, $data['password'], $data['request_id']);

        return redirect()->route($service->routePrefix($owner).'.index')->with('status', 'Other product confirmed. Accounts and billing remain separate.');
    }

    public function cancel(Request $request, ProductPromotionService $service)
    {
        $owner = $service->owner($request);
        $data = $request->validate(['request_id' => ['required', 'uuid']]);
        $service->cancel($owner, $data['request_id']);

        return redirect()->route($service->routePrefix($owner).'.index')->with('status', 'Request cancelled.');
    }

    public function revoke(Request $request, ProductPromotionService $service)
    {
        $owner = $service->owner($request);
        $data = $request->validate(['recognition_id' => ['required', 'uuid']]);
        $service->revoke($owner, $data['recognition_id']);

        return redirect()->route($service->routePrefix($owner).'.index')->with('status', 'Recognition removed. Your promotion preferences are unchanged.');
    }
}
