<?php

namespace App\Http\Controllers;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformProduct;
use App\Models\Platform\PlatformSetting;
use App\Models\ShopEditionRequest;
use App\Services\SubscriptionPaymentService;
use App\Support\ShopEdition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Razorpay\Api\Errors\SignatureVerificationError;

/**
 * Owner-facing /settings/services page.
 *
 * Remove is self-serve (with guards). Adds have two paths:
 *   - PAID self-serve checkout (startAdd → initiateAdd → addCallback): for
 *     products the owner can buy themselves. On payment success a NEW product
 *     subscription is created (Phase 1 createSubscription), which grants the
 *     corresponding edition. One shop = many product subscriptions.
 *   - Admin-review request (requestAdd): the fallback for non-self-serve
 *     products / pilots. A platform admin reviews and grants (source=admin_grant).
 */
class ShopServicesController extends Controller
{
    public function __construct(
        private readonly SubscriptionPaymentService $paymentService,
    ) {}
    /**
     * Services now lives as a tab inside the unified Settings page. This
     * standalone GET route is kept only so old bookmarks/links to
     * /settings/services land on the new tab instead of 404-ing.
     */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('settings.edit', ['tab' => 'services']);
    }

    /**
     * Owner requests to add an edition. Creates a pending request for admin review.
     */
    public function requestAdd(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $shop = $user->shop;

        abort_unless($shop, 403);

        $validated = $request->validate([
            'edition' => ['required', 'string', Rule::in(ShopEdition::ALL)],
            'reason'  => ['required', 'string', 'min:10', 'max:500'],
        ]);

        if (! in_array($validated['edition'], PlatformSetting::enabledShopTypes(), true)) {
            return back()->with('error', ucfirst($validated['edition']).' is not currently available for new activations.');
        }

        if ($shop->hasEdition($validated['edition'])) {
            return back()->with('error', 'Your shop already has '.$validated['edition'].'.');
        }

        $hasPending = ShopEditionRequest::where('shop_id', $shop->id)
            ->where('edition', $validated['edition'])
            ->where('action', ShopEditionRequest::ACTION_ADD)
            ->where('status', ShopEditionRequest::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            return back()->with('error', 'A request to add '.$validated['edition'].' is already pending review.');
        }

        ShopEditionRequest::create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'action'  => ShopEditionRequest::ACTION_ADD,
            'edition' => $validated['edition'],
            'reason'  => trim($validated['reason']),
            'status'  => ShopEditionRequest::STATUS_PENDING,
        ]);

        return back()->with('success', 'Request submitted. Our team will review and contact you within 1 business day.');
    }

    /**
     * PAID self-serve add — step 1: pick a product + billing cycle and start a
     * Razorpay checkout for it. Returns the order details as JSON so the front
     * end can open Razorpay (same shape as SubscriptionController::initiatePayment).
     *
     * This creates a NEW product subscription on success (addCallback) — it does
     * NOT touch any existing subscription. One shop = many product subscriptions.
     */
    public function initiateAdd(Request $request): JsonResponse
    {
        $user = Auth::user();
        $shop = $user->shop;

        abort_unless($shop, 403);

        $validated = $request->validate([
            'product'       => ['required', 'string', Rule::exists('platform_products', 'code')],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
        ]);

        $product = PlatformProduct::where('code', $validated['product'])->first();

        if (! $product || ! $product->is_active) {
            return response()->json(['error' => 'This service is not available to add right now.'], 422);
        }

        $edition = $product->editionString();

        if ($shop->hasEdition($edition)) {
            return response()->json(['error' => 'Your shop already has this service.'], 422);
        }

        $plan = $this->planForProduct($product, $validated['billing_cycle']);
        if (! $plan) {
            return response()->json(['error' => 'No plan is available for this service. Please contact support.'], 422);
        }

        $price = $validated['billing_cycle'] === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        if (is_null($price) || (float) $price <= 0) {
            return response()->json(['error' => 'This service has invalid pricing. Please contact support.'], 422);
        }

        try {
            $order = $this->paymentService->createRazorpayOrder($plan, $validated['billing_cycle']);

            session([
                'services_add_order_id'      => $order->id,
                'services_add_plan_id'       => $plan->id,
                'services_add_billing_cycle' => $validated['billing_cycle'],
            ]);

            return response()->json([
                'order_id'     => $order->id,
                'amount'       => (int) round($price * 100),
                'currency'     => 'INR',
                'key_id'       => config('services.razorpay.key_id'),
                'plan_name'    => $plan->name,
                'user_name'    => $user->name ?? $user->mobile_number,
                'user_email'   => $user->email ?? '',
                'user_contact' => $user->mobile_number ?? '',
            ]);
        } catch (\Throwable $e) {
            Log::error('Service-add Razorpay order creation failed', [
                'shop_id' => $shop->id,
                'product' => $product->code,
                'error'   => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Could not start payment. Please try again.'], 500);
        }
    }

    /**
     * PAID self-serve add — step 2: verify the Razorpay payment and create a NEW
     * product subscription for this shop. createSubscription() grants the matching
     * edition (source=subscription). Idempotent against duplicate callbacks.
     */
    public function addCallback(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $shop = $user->shop;

        abort_unless($shop, 403);

        $paymentId = $request->input('razorpay_payment_id');
        $orderId   = $request->input('razorpay_order_id');
        $signature = $request->input('razorpay_signature');

        if (! $paymentId || ! $orderId || ! $signature) {
            return back()->with('error', 'Payment could not be verified. Please try again.');
        }

        try {
            $this->paymentService->verifyPaymentSignature($orderId, $paymentId, $signature);
            $orderData    = $this->paymentService->fetchAndValidateOrder($orderId);
            $plan         = $orderData['plan'];
            $billingCycle = $orderData['billing_cycle'];
            $this->paymentService->verifyAmount($orderData['order'], $plan, $billingCycle);
            $this->paymentService->verifyPaymentCaptured($paymentId);
        } catch (SignatureVerificationError $e) {
            Log::error('Service-add signature verification failed', ['payment_id' => $paymentId]);
            return back()->with('error', 'Payment signature invalid. Contact support with ref: '.$paymentId);
        } catch (\Throwable $e) {
            Log::error('Service-add payment verification failed', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
            return back()->with('error', 'Could not verify payment. Contact support with ref: '.$paymentId);
        }

        // Idempotency: a duplicate callback returns the existing subscription.
        $existing = $this->paymentService->findExistingSubscription($paymentId);
        if ($existing) {
            session()->forget(['services_add_order_id', 'services_add_plan_id', 'services_add_billing_cycle']);
            return redirect()->route('settings.edit', ['tab' => 'services'])
                ->with('success', 'Your new service is already active.');
        }

        $expectedPrice = $billingCycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly;

        try {
            // createSubscription reads Auth::user()->shop_id for the new row and
            // grants the edition this product unlocks (source=subscription).
            $this->paymentService->createSubscription(
                $plan, $billingCycle, (float) $expectedPrice, $paymentId, $orderId
            );
        } catch (\Throwable $e) {
            Log::error('Service-add createSubscription failed', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
            return back()->with('error', 'Could not activate the service. Contact support with ref: '.$paymentId);
        }

        session()->forget(['services_add_order_id', 'services_add_plan_id', 'services_add_billing_cycle']);

        return redirect()->route('settings.edit', ['tab' => 'services'])
            ->with('success', 'Your new service is now active.');
    }

    /**
     * The active plan for a product at the requested billing cycle. Monthly =
     * a plan with price_yearly NULL; yearly = a plan carrying a price_yearly.
     */
    private function planForProduct(PlatformProduct $product, string $billingCycle): ?Plan
    {
        $plans = Plan::whereRaw('is_active IS TRUE')
            ->where('platform_product_id', $product->id)
            ->orderBy('price_monthly')
            ->get();

        if ($billingCycle === 'yearly') {
            return $plans->first(fn (Plan $p) => ! is_null($p->price_yearly));
        }

        // Prefer a pure-monthly plan (no yearly price); otherwise any plan works.
        return $plans->first(fn (Plan $p) => is_null($p->price_yearly)) ?? $plans->first();
    }

    /**
     * Owner removes an edition. Self-serve if data guards pass; otherwise
     * falls through to a remove-request that support coordinates.
     */
    public function remove(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $shop = $user->shop;

        abort_unless($shop, 403);

        $validated = $request->validate([
            'edition'   => ['required', 'string', Rule::in(ShopEdition::ALL)],
            'reason'    => ['required', 'string', 'min:4', 'max:500'],
            'confirm'   => ['required', 'accepted'],
        ]);

        $edition = $validated['edition'];

        if (! $shop->hasEdition($edition)) {
            return back()->with('error', ucfirst($edition).' is not currently active for your shop.');
        }

        if (count($shop->editionList()) <= 1) {
            return back()->with('error', 'You cannot remove the only active service. Contact support to cancel your subscription instead.');
        }

        if ($guard = $this->dataGuard($shop, $edition)) {
            // Data guard hit — file a remove-request so support can help.
            ShopEditionRequest::create([
                'shop_id' => $shop->id,
                'user_id' => $user->id,
                'action'  => ShopEditionRequest::ACTION_REMOVE,
                'edition' => $edition,
                'reason'  => $validated['reason']." [blocked: {$guard}]",
                'status'  => ShopEditionRequest::STATUS_PENDING,
            ]);

            return back()->with('error', $guard.' Our team has been notified and will contact you to help close this out.');
        }

        DB::transaction(function () use ($shop, $edition, $user, $validated) {
            ShopEdition::revokeFrom($shop, $edition, null, $validated['reason']);

            if ($edition === ShopEdition::DHIRAN) {
                DhiranSettings::where('shop_id', $shop->id)
                    ->update(['is_enabled' => '0']);
            }
        });

        return back()->with('success', ucfirst($edition).' has been removed from your shop.');
    }

    /**
     * Owner cancels their own pending request.
     */
    public function cancelRequest(Request $request, ShopEditionRequest $editionRequest): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($editionRequest->shop_id === $user->shop_id, 403);
        abort_unless($editionRequest->isPending(), 422, 'Only pending requests can be cancelled.');

        $editionRequest->update([
            'status'      => ShopEditionRequest::STATUS_CANCELLED,
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Request cancelled.');
    }

    private function dataGuard($shop, string $edition): ?string
    {
        if ($edition === ShopEdition::DHIRAN) {
            $active = DB::table('dhiran_loans')
                ->where('shop_id', $shop->id)
                ->whereIn('status', ['active', 'renewed'])
                ->exists();
            if ($active) {
                return 'Cannot remove Dhiran while you have active or renewed loans.';
            }
        }

        return null;
    }
}
