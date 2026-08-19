<?php

namespace App\Http\Controllers\Historical;

use App\Http\Controllers\Controller;
use App\Models\Historical\HistoricalSalesDocument;
use App\Services\Historical\HistoricalDocumentLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Corrections to PUBLISHED historical records. A published document is evidence
 * and is never edited or deleted — it is voided (with a reason) or superseded by
 * a corrected record, both recorded with an actor and a timestamp.
 *
 * Both models are resolved through the tenant scope, so a document from another
 * shop is 404, and supersede additionally refuses a cross-shop replacement in the
 * service itself.
 */
class HistoricalDocumentController extends Controller
{
    public function __construct(private readonly HistoricalDocumentLifecycleService $lifecycle) {}

    public function void(Request $request, HistoricalSalesDocument $document): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->lifecycle->void($document, (int) $request->user()->id, $data['reason']);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Document voided. Its number and fingerprint are released.');
    }

    public function supersede(Request $request, HistoricalSalesDocument $document): RedirectResponse
    {
        $data = $request->validate([
            'replacement_id' => ['required', 'integer', 'different:document'],
            'reason'         => ['nullable', 'string', 'max:500'],
        ]);

        // Tenant-scoped lookup: a replacement from another shop is simply not found.
        $replacement = HistoricalSalesDocument::find((int) $data['replacement_id']);

        if ($replacement === null) {
            return back()->with('error', 'The replacement document was not found.');
        }

        try {
            $this->lifecycle->supersede($document, $replacement, (int) $request->user()->id);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Document superseded. The original is kept as evidence.');
    }

    /**
     * Operator-confirmed customer link. The snapshot is never disturbed.
     * Shop membership, archive state, and draft-only eligibility are all
     * revalidated inside the service — never trust a posted customer_id.
     */
    public function linkCustomer(Request $request, HistoricalSalesDocument $document): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
        ]);

        try {
            $this->lifecycle->linkCustomer($document, $data['customer_id'] ?? null);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Customer link updated.');
    }
}
