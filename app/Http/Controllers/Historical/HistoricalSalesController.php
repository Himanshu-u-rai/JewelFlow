<?php

namespace App\Http\Controllers\Historical;

use App\Http\Controllers\Controller;
use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Services\Historical\HistoricalCustomerMatcher;
use App\Services\Historical\HistoricalOpeningBalanceEvaluator;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The read side of the Historical Sales module: the landing list and the
 * published-document view.
 *
 * Every model here is resolved through the BelongsToShop global scope, so a
 * document or batch belonging to another shop is simply "not found" (404) rather
 * than forbidden — the safest failure, and the one Phase 3 asks for on cross-shop
 * access. Nothing in this controller writes.
 */
class HistoricalSalesController extends Controller
{
    /** Landing / list. Documents and batches needed to operate the module. */
    public function index(Request $request): View
    {
        $documents = HistoricalSalesDocument::query()
            ->with('batch:id,label,status')
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->paginate(25);

        $batches = HistoricalImportBatch::query()
            ->withCount(['documents', 'rows'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('historical.index', compact('documents', 'batches'));
    }

    /** One historical document — always badged, never dressed as a live invoice. */
    public function showDocument(
        HistoricalSalesDocument $document,
        HistoricalCustomerMatcher $matcher,
        HistoricalOpeningBalanceEvaluator $openingBalance,
    ): View {
        $document->load(['lines', 'batch:id,label,status', 'revises', 'supersededBy', 'customer', 'openingBalanceResolver:id,name']);

        // Suggestions only matter while a link can still be made — linking is
        // draft-only (Batch 3), so a published/void/superseded document never
        // needs a fresh candidate list.
        $suggestions = $document->status === HistoricalSalesDocument::STATUS_DRAFT
            ? $matcher->suggest(
                $document->shop_id,
                $document->customer_snapshot['name'] ?? null,
                $document->customer_snapshot['mobile'] ?? null,
                $document->customer_snapshot['gstin'] ?? null,
            )
            : null;

        // Recomputed live, never cached — the customer's opening-balance rows
        // may have changed since this document was linked or last resolved.
        $openingBalanceSeverity = $openingBalance->evaluate($document);

        return view('historical.document', compact('document', 'suggestions', 'openingBalanceSeverity'));
    }

    /** A batch's status/detail page (also the preview + reconciliation surface). */
    public function showBatch(HistoricalImportBatch $batch): View
    {
        $batch->load(['profile', 'creator:id,name', 'publisher:id,name']);

        $rows = $batch->rows()
            ->orderBy('source_sheet')
            ->orderBy('source_row_number')
            ->paginate(100);

        $documents = $batch->documents()->with('lines:id,historical_sales_document_id')->get();

        return view('historical.batch', [
            'batch'     => $batch,
            'rows'      => $rows,
            'documents' => $documents,
            'preview'   => $batch->preview_summary ?? [],
            'blocker'   => $batch->blockedFromPublishing(),
        ]);
    }
}
