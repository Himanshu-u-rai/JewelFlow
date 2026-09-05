<?php

namespace App\Http\Controllers\Historical;

use App\Data\Mobile\V1\MaterialRegistrySnapshot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Historical\StoreManualHistoricalRequest;
use App\Models\Customer;
use App\Models\Shop;
use App\Models\ShopPaymentMethod;
use App\Services\Historical\HistoricalCustomerMatcher;
use App\Services\Historical\HistoricalImportService;
use App\Services\MetalRegistry;
use App\Services\ShopPricingService;
use App\Support\Historical\HistoricalFields;
use App\Support\Historical\HistoricalMakingCharge;
use App\Support\Historical\HistoricalManualPublishRejected;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Manual entry of a completed historical bill. It does NOT get its own validation
 * or persistence rules: it hands the typed bill to the very same
 * HistoricalImportService::storeManual() that a file import converges on, so a
 * typed bill and an imported bill are the same record checked by the same code.
 */
class HistoricalManualEntryController extends Controller
{
    public function __construct(
        private readonly HistoricalImportService $imports,
        private readonly HistoricalCustomerMatcher $matcher,
        private readonly ShopPricingService $pricing,
    ) {}

    /**
     * Where every refusal goes: the entry form, carrying the typed bill.
     *
     * Never back(). The operator reaches store()/preview() from the preview
     * screen, so the referer is the POST-only preview URL — and a redirect is a
     * GET, which lands on previewExpired() and redirects a second time. That
     * second hop consumes the flashed input and overwrites the real reason with
     * "preview has expired", losing the bill and explaining nothing.
     */
    private function backToForm(): RedirectResponse
    {
        return redirect()->route('historical.manual.create')->withInput();
    }

    public function create(): View
    {
        return view('historical.manual', $this->formData(auth()->user()->shop) + [
            'headerFields' => HistoricalFields::HEADER,
            'lineFields' => HistoricalFields::LINE,
            'makingCategories' => HistoricalMakingCharge::CATEGORIES,
            'makingBases' => HistoricalMakingCharge::BASES,
            // Fast-entry "& New" success path only — see freshFormAfter(). A
            // plain visit (first load, previewExpired(), or a failed submit's
            // backToForm()) never sets this flash key, so it is null and the
            // form renders exactly as it always has.
            'carriedForward' => session('historical_carry_forward'),
        ]);
    }

    private function formData(Shop $shop): array
    {
        $registry = MaterialRegistrySnapshot::forShop((int) $shop->id);
        $purityProfiles = $this->pricing->activePurityProfiles($shop)
            ->map(fn ($profile): array => [
                'metal' => strtolower((string) $profile->metal_type),
                'label' => (string) $profile->label,
                'value' => (float) $profile->purity_value,
            ])
            ->values()
            ->all();

        foreach ($registry->metals as $metal => $descriptor) {
            if (MetalRegistry::purityIsAccountingTruth($metal)) {
                continue;
            }

            foreach ($descriptor->active_purity_profiles as $value) {
                $purityProfiles[] = ['metal' => $metal, 'label' => (string) $value, 'value' => (float) $value];
            }
        }

        // Batch 3 §7/§8 — the manual-entry payment row's account dropdown.
        // Explicit shop_id filter (not just the tenant global scope), same
        // style as the purity profiles above: a view-render code path stays
        // correct regardless of what implicitly scopes the query elsewhere.
        $shopPaymentMethods = ShopPaymentMethod::query()
            ->where('shop_id', $shop->id)
            ->active()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ShopPaymentMethod $method): array => [
                'id' => $method->id,
                'label' => $method->account_label,
            ])
            ->values()
            ->all();

        return [
            'enabledMetals' => $registry->enabled_metals,
            'purityProfiles' => $purityProfiles,
            'shopPaymentMethods' => $shopPaymentMethods,
        ];
    }

    /**
     * Active, tenant-scoped candidates for the manual-entry combobox. The
     * response is intentionally not a Customer serialization: selection needs
     * only an id, display name, masked mobile, and optional business type.
     */
    public function searchCustomers(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['status' => 'snapshot_only', 'results' => []]);
        }

        $shopId = (int) $request->user()->shop_id;
        $digits = preg_replace('/\D+/', '', $query) ?? '';

        $customers = Customer::withoutTenant()
            ->where('shop_id', $shopId)
            ->active()
            ->where(function ($builder) use ($query, $digits): void {
                $builder->where('first_name', 'ilike', "%{$query}%")
                    ->orWhere('last_name', 'ilike', "%{$query}%")
                    ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) ILIKE ?", ["%{$query}%"])
                    ->orWhere('gstin', 'ilike', "%{$query}%");

                if (strlen($digits) >= 3) {
                    $builder->orWhere('mobile', 'like', "%{$digits}%");
                }
            })
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'first_name', 'last_name', 'mobile', 'customer_type']);

        if ($customers->isNotEmpty()) {
            return response()->json([
                'status' => 'matches',
                'results' => $customers->map(fn (Customer $customer): array => [
                    'id' => (int) $customer->id,
                    'name' => $customer->name,
                    'mobile_masked' => self::maskedMobile($customer->mobile),
                    'customer_type' => $customer->customer_type,
                ])->values(),
            ]);
        }

        $mobile = Customer::normalizeMobile($query);
        $archived = $mobile !== null && Customer::withoutTenant()
            ->where('shop_id', $shopId)
            ->archived()
            ->where('mobile', $mobile)
            ->exists();

        return response()->json([
            'status' => $archived ? 'archived_action_required' : ($mobile === null ? 'snapshot_only' : 'new'),
            'results' => [],
        ]);
    }

    private static function maskedMobile(?string $mobile): string
    {
        $mobile = Customer::normalizeMobile($mobile);

        return $mobile === null ? 'Mobile unavailable' : substr($mobile, 0, 2).'******'.substr($mobile, -2);
    }

    /**
     * Zero-write preview. Confirm Save re-posts the same raw fields to
     * store() — nothing computed here is trusted, only recomputed.
     */
    public function preview(StoreManualHistoricalRequest $request): View|RedirectResponse
    {
        $shop = $request->user()->shop;

        try {
            $result = $this->imports->previewManual(
                $shop,
                $request->headerFields(),
                $request->lines(),
                (int) $request->user()->id,
                $request->options(),
                $request->payments(),
            );
        } catch (Throwable $e) {
            // previewManual() never writes to the database — any Throwable here is a
            // genuine computation fault, never a deliberate business refusal (those
            // are collected as $messages, not thrown). $e->getMessage() can carry a
            // raw SQLSTATE/query/bindings for the rare DB-backed lookup (duplicate
            // detection) inside it, so it goes to the log, never to the flash.
            report($e);

            return $this->backToForm()->with(
                'error',
                'This bill could not be calculated. Check the entered values and try again.'
            );
        }

        // Flashes the submitted input into the session so the same old()-backed
        // form fields (shared with the create screen) render prefilled here —
        // Edit is just re-submitting this page, Confirm Save posts these same
        // raw values, never the computed preview numbers.
        $request->flash();

        // Informational only — no document exists yet at preview time, so
        // there is nothing to link. These are shown purely so the operator
        // knows what Save's review screen will likely suggest.
        $snapshot = $result['attributes']['customer_snapshot'] ?? [];
        $suggestions = $this->matcher->suggest(
            $shop->id,
            $snapshot['name'] ?? null,
            $snapshot['mobile'] ?? null,
            $snapshot['gstin'] ?? null,
        );

        return view('historical.manual-preview', $this->formData($shop) + [
            'attributes' => $result['attributes'],
            'lines' => $result['lines'],
            'messages' => $result['messages'],
            // What "I have read the warnings" must be pinned to. Null when this bill
            // has no warnings, in which case the acknowledgement UI has nothing to
            // show and Save & publish needs no tick.
            'warningDigest' => $result['messages']->warningDigest(),
            'fingerprint' => $result['fingerprint'],
            'suggestions' => $suggestions,
            'headerFields' => HistoricalFields::HEADER,
            'lineFields' => HistoricalFields::LINE,
            'makingCategories' => HistoricalMakingCharge::CATEGORIES,
            'makingBases' => HistoricalMakingCharge::BASES,
        ]);
    }

    /**
     * GET on the preview URL. The preview is a POST result, so Back, reload, or a
     * bookmark lands here — previously a 405 in the operator's face.
     *
     * Writes nothing, computes nothing, and carries no submitted values: the bill
     * is not in this request, so the only honest thing to do is send the operator
     * back to the form to re-enter or recalculate.
     *
     * Flashed as `error` rather than `warning` because <x-app-alerts> renders only
     * the `success` and `error` channels — a `warning` flash survives the redirect
     * and is then dropped by the view layer, leaving the operator with a silent
     * bounce back to an empty form and no explanation.
     */
    public function previewExpired(): RedirectResponse
    {
        return redirect()
            ->route('historical.manual.create')
            ->with('error', 'That preview has expired. Enter the bill again and recalculate the preview.');
    }

    /**
     * Save draft, or Save & publish — chosen by the `intent` field, never by the
     * URL, so both live on one form and one route.
     *
     * Neither path ends on the batch page. A manually typed bill is one document;
     * the batch behind it is bookkeeping this operator did not ask for and should
     * not have to read.
     */
    public function store(StoreManualHistoricalRequest $request): RedirectResponse
    {
        if ($request->wantsDirectPublish()) {
            // Defence in depth. The route middleware only guards `historical.import`
            // because Save-draft lives on the same route; publishing needs its own
            // permission and must not be reachable by posting a different intent.
            $this->authorize('historical.publish');

            return $this->storeAndPublish($request);
        }

        $shop = $request->user()->shop;

        try {
            $result = $this->imports->storeManual(
                $shop,
                $request->headerFields(),
                $request->lines(),
                (int) $request->user()->id,
                $request->options(),
                $request->payments(),
            );
        } catch (Throwable $e) {
            // storeManual() throws only for a genuine fault (e.g. a DB error) — a
            // deliberate business refusal is returned as $result['messages'], not
            // thrown. $e->getMessage() can be a raw SQLSTATE/query/bindings string,
            // so it goes to the log, never to the flashed, user-facing error.
            report($e);

            return $this->backToForm()->with(
                'error',
                'This bill could not be saved and nothing was recorded. Try again, or contact support if the problem continues.'
            );
        }

        $messages = $result['messages'];

        // Blocking errors mean nothing was written — surface them, keep the form.
        if ($result['document'] === null && $messages->hasBlocking()) {
            return $this->backToForm()
                ->with('historical_messages', $messages->all())
                ->with('error', 'This bill has blocking problems and was not saved. Correct the highlighted fields.');
        }

        // A null document WITHOUT a blocking error is the duplicate-skip path: the
        // operator's decision was honoured and there is no document to open.
        // `error` channel for the same reason as previewExpired() above.
        if ($result['document'] === null) {
            return $this->backToForm()
                ->with('historical_messages', $messages->all())
                ->with('error', 'This bill was not recorded. Review the duplicate decision above.');
        }

        if ($request->wantsFreshFormAfterSuccess()) {
            return $this->freshFormAfter($request, 'Historical bill saved as draft.');
        }

        return redirect()
            ->route('historical.documents.show', $result['document'])
            ->with('historical_messages', $messages->all())
            ->with('success', 'Historical bill saved as draft.');
    }

    /**
     * Batch 3 fast-entry — the "& New" success path shared by a plain draft
     * save and a publish. Only ever reached after storeManual()/
     * publishManual() actually wrote a document; every failure path still
     * goes through backToForm() unchanged, so a rejected bill is never
     * silently replaced with a blank one.
     */
    private function freshFormAfter(StoreManualHistoricalRequest $request, string $message): RedirectResponse
    {
        return redirect()
            ->route('historical.manual.create')
            ->with('success', $message)
            ->with('historical_carry_forward', $request->carryForwardFields());
    }

    /**
     * Create and publish in one atomic step. A refusal rolls the attempt back to
     * nothing and returns the operator to their form with the reason — see
     * HistoricalImportService::publishManual() for the exact failure semantics.
     */
    private function storeAndPublish(StoreManualHistoricalRequest $request): RedirectResponse
    {
        try {
            $result = $this->imports->publishManual(
                $request->user()->shop,
                $request->headerFields(),
                $request->lines(),
                (int) $request->user()->id,
                $request->options(),
                $request->acknowledgedWarningDigest(),
                $request->payments(),
            );
        } catch (HistoricalManualPublishRejected $e) {
            return $this->backToForm()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->backToForm()->with(
                'error',
                'This bill could not be published and nothing was saved. Try again, or save it as a draft first.'
            );
        }

        if ($request->wantsFreshFormAfterSuccess()) {
            return $this->freshFormAfter($request, 'Historical bill saved and published.');
        }

        return redirect()
            ->route('historical.documents.show', $result['document'])
            ->with('historical_messages', $result['messages']->all())
            ->with('success', 'Historical bill saved and published.');
    }
}
