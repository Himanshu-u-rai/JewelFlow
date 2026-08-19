<?php

namespace App\Http\Controllers\Historical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Historical\StoreManualHistoricalRequest;
use App\Services\Historical\HistoricalImportService;
use App\Support\Historical\HistoricalFields;
use App\Support\Historical\HistoricalMakingCharge;
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
    public function __construct(private readonly HistoricalImportService $imports) {}

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

        return view('historical.manual-preview', [
            'attributes'       => $result['attributes'],
            'lines'            => $result['lines'],
            'messages'         => $result['messages'],
            'fingerprint'      => $result['fingerprint'],
            'headerFields'     => HistoricalFields::HEADER,
            'lineFields'       => HistoricalFields::LINE,
            'makingCategories' => HistoricalMakingCharge::CATEGORIES,
            'makingBases'      => HistoricalMakingCharge::BASES,
        ]);
    }

    public function store(StoreManualHistoricalRequest $request): RedirectResponse
    {
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

        return redirect()
            ->route('historical.batches.show', $result['batch'])
            ->with('success', 'Historical bill saved as a draft. Review it, then publish.');
    }
}
