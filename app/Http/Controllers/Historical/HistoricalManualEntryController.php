<?php

namespace App\Http\Controllers\Historical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Historical\StoreManualHistoricalRequest;
use App\Services\Historical\HistoricalCustomerMatcher;
use App\Services\Historical\HistoricalImportService;
use App\Support\Historical\HistoricalFields;
use App\Support\Historical\HistoricalMakingCharge;
use App\Support\Historical\HistoricalManualPublishRejected;
use Illuminate\Http\RedirectResponse;
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
    ) {}

    public function create(): View
    {
        return view('historical.manual', [
            'headerFields'    => HistoricalFields::HEADER,
            'lineFields'      => HistoricalFields::LINE,
            'makingCategories' => HistoricalMakingCharge::CATEGORIES,
            'makingBases'     => HistoricalMakingCharge::BASES,
        ]);
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
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
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

        return view('historical.manual-preview', [
            'attributes'       => $result['attributes'],
            'lines'            => $result['lines'],
            'messages'         => $result['messages'],
            // What "I have read the warnings" must be pinned to. Null when this bill
            // has no warnings, in which case the acknowledgement UI has nothing to
            // show and Save & publish needs no tick.
            'warningDigest'    => $result['messages']->warningDigest(),
            'fingerprint'      => $result['fingerprint'],
            'suggestions'      => $suggestions,
            'headerFields'     => HistoricalFields::HEADER,
            'lineFields'       => HistoricalFields::LINE,
            'makingCategories' => HistoricalMakingCharge::CATEGORIES,
            'makingBases'      => HistoricalMakingCharge::BASES,
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
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $messages = $result['messages'];

        // Blocking errors mean nothing was written — surface them, keep the form.
        if ($result['document'] === null && $messages->hasBlocking()) {
            return back()
                ->withInput()
                ->with('historical_messages', $messages->all())
                ->with('error', 'This bill has blocking problems and was not saved. Correct the highlighted fields.');
        }

        // A null document WITHOUT a blocking error is the duplicate-skip path: the
        // operator's decision was honoured and there is no document to open.
        // `error` channel for the same reason as previewExpired() above.
        if ($result['document'] === null) {
            return back()
                ->withInput()
                ->with('historical_messages', $messages->all())
                ->with('error', 'This bill was not recorded. Review the duplicate decision above.');
        }

        return redirect()
            ->route('historical.documents.show', $result['document'])
            ->with('historical_messages', $messages->all())
            ->with('success', 'Historical bill saved as draft.');
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
            );
        } catch (HistoricalManualPublishRejected $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with(
                'error',
                'This bill could not be published and nothing was saved. Try again, or save it as a draft first.'
            );
        }

        return redirect()
            ->route('historical.documents.show', $result['document'])
            ->with('historical_messages', $result['messages']->all())
            ->with('success', 'Historical bill saved and published.');
    }
}
