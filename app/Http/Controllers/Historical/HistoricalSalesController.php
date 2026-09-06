<?php

namespace App\Http\Controllers\Historical;

use App\Http\Controllers\Controller;
use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Services\Historical\HistoricalCustomerMatcher;
use App\Services\Historical\HistoricalOpeningBalanceEvaluator;
use App\Support\Historical\HistoricalMessages;
use Illuminate\Http\RedirectResponse;
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

        // File imports only. A manually typed bill also owns a HistoricalImportBatch
        // — the publish gate, the finding counters and the acknowledgement stamp all
        // live on that row — but the operator never asked for it and its URL now
        // forwards to the document (showBatch below). Listing it here would put an
        // implementation detail in a panel about imports.
        //
        // Keyed off source_file_name, the same structural discriminator
        // HistoricalImportBatch::isManualBatch() uses: only createBatchFromUpload()
        // ever writes it, and storeManual() never does. Deliberately NOT source_system,
        // which is free text the operator can edit on the manual form.
        $batches = HistoricalImportBatch::query()
            ->whereNotNull('source_file_name')
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
        // The batch columns are no longer cosmetic: for a manual bill this page is
        // the review surface, so it needs the same state the batch page reads —
        // source_file_name (manual vs file import), the finding counters, the
        // acknowledgement stamp and the stored findings.
        $document->load([
            'lines',
            'batch:id,label,status,source_file_name,blocking_count,warning_count,'
                . 'warnings_acknowledged_at,preview_generated_at,preview_summary',
            'revises',
            'supersededBy',
            'customer',
            'openingBalanceResolver:id,name',
            'attachments' => fn ($query) => $query->orderByDesc('created_at'),
            'attachments.uploadedBy:id,name',
            'attachments.removedBy:id,name',
        ]);

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

        // The lifecycle contract the document page renders from. For a manual bill
        // this page IS the review surface, so everything the batch page would have
        // shown about publishability has to be answerable here — computed from the
        // same batch state and the same publish gate, never re-derived.
        $batch = $document->batch;

        $findings = HistoricalMessages::fromArray(
            ($batch?->preview_summary ?? [])['manual_messages'] ?? null
        );

        $lifecycle = [
            // Is this document's batch the private one-document batch behind a typed
            // bill, or a real file import whose batch page still owns the workflow?
            'is_manual'            => (bool) $batch?->isManualBatch(),
            'batch_status'         => $batch?->status,
            'blocking'             => $findings->ofSeverity(HistoricalMessages::ERROR),
            'warnings'             => $findings->ofSeverity(HistoricalMessages::WARNING),
            'informational'        => $findings->ofSeverity(HistoricalMessages::INFO),
            'blocking_count'       => (int) ($batch?->blocking_count ?? 0),
            'warning_count'        => (int) ($batch?->warning_count ?? 0),
            'warnings_acknowledged' => (bool) $batch?->warningsAcknowledged(),
            'acknowledged_at'      => $batch?->warnings_acknowledged_at,
            // Null means publishable right now; a string is the reason it is not,
            // straight from HistoricalImportBatch::blockedFromPublishing().
            'publish_blocker'      => $batch?->blockedFromPublishing(),
            'can_publish'          => $document->status === HistoricalSalesDocument::STATUS_DRAFT
                && $batch !== null
                && $batch->blockedFromPublishing() === null,
            'is_draft'             => $document->status === HistoricalSalesDocument::STATUS_DRAFT,
            'is_published'         => $document->status === HistoricalSalesDocument::STATUS_PUBLISHED,
            'is_void'              => $document->status === HistoricalSalesDocument::STATUS_VOID,
            'is_superseded'        => $document->status === HistoricalSalesDocument::STATUS_SUPERSEDED,
            'customer_linked'      => $document->customer_id !== null,
            'opening_balance_overlap'    => (bool) $document->opening_balance_overlap,
            'opening_balance_resolution' => $document->opening_balance_resolution,
        ];

        return view('historical.document', compact(
            'document',
            'suggestions',
            'openingBalanceSeverity',
            'lifecycle',
        ));
    }

    /**
     * A batch's status/detail page (also the preview + reconciliation surface).
     *
     * A manual bill's batch is an implementation detail — the operator never chose
     * to create it — so its URL forwards to the document it exists for. File
     * imports are untouched: their batch IS the unit of work.
     */
    public function showBatch(HistoricalImportBatch $batch): View|RedirectResponse
    {
        if ($batch->isManualBatch()) {
            $manualDocuments = $batch->documents()->orderBy('id')->get(['id']);

            if ($manualDocuments->count() === 1) {
                return redirect()->route('historical.documents.show', $manualDocuments->first()->id);
            }

            // Zero or several documents on a batch that claims to be manual is not a
            // shape this flow can produce. Rather than guess which document the
            // operator meant (or forward to nothing), fall through to the batch page
            // and say plainly that the data is unusual.
            //
            // Flashed as `error`, not `warning`: <x-app-alerts> renders only the
            // `success` and `error` channels, so anything flashed as `warning` is
            // stored and then silently discarded by the view layer.
            session()->now('error', sprintf(
                'This manual batch holds %d documents instead of one, so the batch view is shown. '
                . 'Please report batch #%d.',
                $manualDocuments->count(),
                $batch->id
            ));
        }

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
