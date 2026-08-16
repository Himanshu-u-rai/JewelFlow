<?php

namespace App\Http\Controllers\Historical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Historical\SaveHistoricalMappingRequest;
use App\Http\Requests\Historical\UploadHistoricalFileRequest;
use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalImportProfile;
use App\Services\Historical\HistoricalDocumentLifecycleService;
use App\Services\Historical\HistoricalDuplicateDetector;
use App\Services\Historical\HistoricalImportService;
use App\Support\Historical\HistoricalFields;
use App\Support\Historical\HistoricalMakingCharge;
use App\Support\Historical\HistoricalParseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * The whole file-import lifecycle: upload → map → normalize → duplicate review →
 * preview → publish, plus draft rollback. Nothing here publishes on upload; each
 * step is a separate operator action, and publication is gated behind the
 * batch's own blockedFromPublishing() rule so the button and the server agree.
 *
 * The publish action owns the atomic claim and releases it in a `finally`, so a
 * failure mid-publish can never strand the batch in `publishing` (Phase 5).
 */
class HistoricalImportController extends Controller
{
    public function __construct(
        private readonly HistoricalImportService $imports,
        private readonly HistoricalDocumentLifecycleService $lifecycle,
    ) {}

    // ------------------------------------------------------------- upload

    public function create(Request $request): View
    {
        // DB::raw('true'), not a bound PHP value: with emulated prepares (this
        // connection's config) an `int` binding is inlined as a bare numeric
        // literal, and Postgres refuses `boolean = integer` with no cast. A
        // bound bool/string would dodge this one query, but the raw literal
        // is what CustomerController::index() already uses for this exact
        // column type, so it's the proven-safe convention here.
        $profiles = HistoricalImportProfile::query()
            ->where('is_active', DB::raw('true'))
            ->orderBy('name')
            ->get(['id', 'name', 'source_system', 'layout_type']);

        return view('historical.upload', compact('profiles'));
    }

    public function store(UploadHistoricalFileRequest $request): RedirectResponse
    {
        $shop = $request->user()->shop;

        $profile = $request->filled('historical_import_profile_id')
            ? HistoricalImportProfile::find((int) $request->input('historical_import_profile_id'))
            : null;

        try {
            $batch = $this->imports->createBatchFromUpload(
                $shop,
                $request->file('file'),
                $profile,
                (int) $request->user()->id,
                $request->only(['label', 'source_system', 'cutover_date']),
            );
        } catch (HistoricalParseException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('historical.batches.map', $batch)
            ->with('success', 'File uploaded. Confirm the column mapping before anything is read.');
    }

    // -------------------------------------------------------------- mapping

    public function map(HistoricalImportBatch $batch): View
    {
        $this->assertEditable($batch);

        $profile = $batch->profile;

        try {
            $sheets = $this->imports->inspect($batch);
        } catch (HistoricalParseException $e) {
            return view('historical.map', [
                'batch'          => $batch,
                'error'          => $e->getMessage(),
                'sheets'         => [],
                'headers'        => [],
                'detailHeaders'  => [],
                'headersBySheet' => [],
                'suggestion'     => ['mapping' => [], 'unmapped' => []],
                'samples'        => [],
                'headerFields' => HistoricalFields::HEADER,
                'lineFields'   => HistoricalFields::LINE,
                'formats'      => \App\Services\Historical\HistoricalDateParser::FORMATS,
                'layouts'      => HistoricalImportProfile::LAYOUTS,
                'makingCategories' => HistoricalMakingCharge::CATEGORIES,
                'makingBases'  => HistoricalMakingCharge::BASES,
                'profile'      => $profile,
            ]);
        }

        $headerRow  = (int) ($profile?->header_row ?: 1);
        $sheetNames = array_column($sheets, 'name');

        // The header sheet always has a usable default (the workbook's first
        // sheet — unchanged from before this fix). The detail sheet only
        // defaults to something when a second sheet actually exists; a plain
        // single-sheet CSV/XLSX (Layout A/B) leaves it null, which is exactly
        // today's behaviour for those layouts.
        $headerSheet = $this->resolveSheetName($sheetNames, old('sheets.header', $profile?->sheetFor('header')), $sheetNames[0] ?? null);
        $detailSheet = $this->resolveSheetName($sheetNames, old('sheets.detail', $profile?->sheetFor('detail')), $sheetNames[1] ?? null);

        // Every visible sheet's headers, keyed by name — read once so the
        // mapping screen's own JS can swap a dropdown's options the instant the
        // operator changes which sheet plays which role, with no round trip.
        $headersBySheet = [];
        foreach ($sheetNames as $name) {
            $headersBySheet[$name] = $this->imports->headers($batch, $name, $headerRow);
        }

        $headers       = $headersBySheet[$headerSheet] ?? [];
        // Layout A/B have no separate detail sheet: line fields (Layout B) map
        // against the same single sheet as everything else, so this falls back
        // to $headers rather than being empty.
        $detailHeaders = $detailSheet !== null ? ($headersBySheet[$detailSheet] ?? []) : $headers;

        $suggestion = $this->imports->suggest($headers);
        $samples    = $this->imports->sample($batch, $headerSheet, $headerRow, $headers);

        return view('historical.map', [
            'batch'          => $batch,
            'error'          => null,
            'sheets'         => $sheets,
            'headers'        => $headers,
            'detailHeaders'  => $detailHeaders,
            'headersBySheet' => $headersBySheet,
            'suggestion'   => $suggestion,
            'samples'      => $samples,
            'headerFields' => HistoricalFields::HEADER,
            'lineFields'   => HistoricalFields::LINE,
            'formats'      => \App\Services\Historical\HistoricalDateParser::FORMATS,
            'layouts'      => HistoricalImportProfile::LAYOUTS,
            'makingCategories' => HistoricalMakingCharge::CATEGORIES,
            'makingBases'  => HistoricalMakingCharge::BASES,
            'profile'      => $profile,
        ]);
    }

    /**
     * Persist the confirmed mapping onto a reusable profile, then stage and
     * normalize the file through it. Re-runnable: normalize() rebuilds the draft
     * documents from scratch each time, so a corrected mapping just re-produces
     * the preview.
     */
    public function saveMapping(SaveHistoricalMappingRequest $request, HistoricalImportBatch $batch): RedirectResponse
    {
        $this->assertEditable($batch);

        $shop  = $request->user()->shop;
        $attrs = $request->profileAttributes() + ['shop_id' => $shop->id];

        // `source_system` is NOT NULL on the profile table (Batch 1 schema). The
        // mapping form leaves it optional — the operator already typed it (or
        // didn't) on the upload screen — so fall back to the batch's value, and
        // only then to a generic label. Never let an omitted field 500.
        $attrs['source_system'] = $attrs['source_system'] ?: ($batch->source_system ?: 'Import');

        $profile = $batch->profile ?? new HistoricalImportProfile();
        $profile->fill($attrs)->save();

        $batch->forceFill([
            'historical_import_profile_id' => $profile->id,
            'layout_type'                  => $profile->layout_type,
            'date_format'                  => $profile->date_format,
        ])->save();

        try {
            $this->imports->stage($batch, $profile);
            $this->imports->normalize($batch, $profile, (int) $request->user()->id);
        } catch (HistoricalParseException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('historical.batches.show', $batch)
            ->with('success', 'Mapping saved and the file normalized. Review the reconciliation preview.');
    }

    /** Re-run normalization after edits, without re-uploading. */
    public function renormalize(Request $request, HistoricalImportBatch $batch): RedirectResponse
    {
        $this->assertEditable($batch);

        $profile = $batch->profile;

        if ($profile === null) {
            return back()->with('error', 'Confirm a column mapping before normalizing.');
        }

        try {
            $this->imports->normalize($batch, $profile, (int) $request->user()->id);
        } catch (HistoricalParseException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Re-normalized. The preview and warnings are refreshed.');
    }

    // -------------------------------------------------------- duplicate review

    /**
     * Record how the operator chose to resolve one grouping's collision, then
     * re-normalize so the choice takes effect. The resolution is stored per
     * grouping key on the batch; a new series must be typed and confirmed, never
     * inferred.
     */
    public function resolveDuplicate(Request $request, HistoricalImportBatch $batch): RedirectResponse
    {
        $this->assertEditable($batch);

        $data = $request->validate([
            'grouping_key'  => ['required', 'string', 'max:255'],
            'action'        => ['required', \Illuminate\Validation\Rule::in(HistoricalDuplicateDetector::RESOLUTIONS)],
            'series'        => ['nullable', 'string', 'max:60'],
            'override_key'  => ['nullable', 'string', 'max:120'],
            'reason'        => ['nullable', 'string', 'max:500'],
        ]);

        $resolutions = $batch->duplicate_resolutions ?? [];
        $resolutions[$data['grouping_key']] = [
            'action'       => $data['action'],
            'series'       => $data['series'] ?? null,
            'override_key' => $data['override_key'] ?? null,
            'reason'       => $data['reason'] ?? null,
            'actor'        => (int) $request->user()->id,
            'at'           => now()->toIso8601String(),
        ];

        $batch->forceFill(['duplicate_resolutions' => $resolutions])->save();

        return $this->renormalize($request, $batch->refresh());
    }

    // -------------------------------------------------------- acknowledge / publish

    public function acknowledgeWarnings(Request $request, HistoricalImportBatch $batch): RedirectResponse
    {
        $this->assertEditable($batch);

        $batch->forceFill([
            'warnings_acknowledged_at' => now(),
            'warnings_acknowledged_by' => (int) $request->user()->id,
        ])->save();

        return back()->with('success', 'Warnings acknowledged. You can now publish this batch.');
    }

    public function publish(Request $request, HistoricalImportBatch $batch): RedirectResponse
    {
        // Idempotent stable result: an already-published batch just re-shows.
        if ($batch->isPublished()) {
            return redirect()
                ->route('historical.batches.show', $batch)
                ->with('success', 'This batch is already published.');
        }

        if ($blocker = $batch->blockedFromPublishing()) {
            return back()->with('error', $blocker);
        }

        // Take the claim HERE so releaseClaim() can live in this method's finally.
        if (! $this->lifecycle->claimForPublishing($batch)) {
            $batch->refresh();

            return $batch->isPublished()
                ? redirect()->route('historical.batches.show', $batch)->with('success', 'This batch is already published.')
                : back()->with('error', 'This batch is currently being published by another request.');
        }

        try {
            $this->lifecycle->publishClaimed($batch, (int) $request->user()->id);
        } catch (Throwable $e) {
            Log::error('Historical batch publish failed', ['batch' => $batch->id, 'error' => $e->getMessage()]);

            return redirect()
                ->route('historical.batches.show', $batch)
                ->with('error', 'Publishing failed and no records were published. Please try again.');
        } finally {
            // Stranded in `publishing` (a throw before commit) → hand back to review.
            if ($batch->fresh()?->status === HistoricalImportBatch::STATUS_PUBLISHING) {
                $this->lifecycle->releaseClaim($batch);
            }
        }

        return redirect()
            ->route('historical.batches.show', $batch)
            ->with('success', 'Historical batch published. These records are now immutable evidence.');
    }

    // ------------------------------------------------------------- rollback

    /** Physically discard an unpublished batch and everything it created. */
    public function destroy(HistoricalImportBatch $batch): RedirectResponse
    {
        if ($batch->isPublished()) {
            return back()->with('error', 'A published batch cannot be rolled back. Void or supersede its documents instead.');
        }

        $this->lifecycle->rollback($batch);

        return redirect()
            ->route('historical.index')
            ->with('success', 'Draft batch rolled back. Nothing it created remains.');
    }

    private function assertEditable(HistoricalImportBatch $batch): void
    {
        abort_unless($batch->isEditable(), 409, 'This batch can no longer be changed.');
    }

    /**
     * A requested sheet name is untrusted (query/session leftovers, an edited
     * profile from a previous, differently-shaped file). Only ever hand the
     * reader a name that is actually in this workbook — anything else falls
     * back rather than risking a "no sheet named ..." exception mid-render.
     *
     * @param  array<int, string>  $sheetNames
     */
    private function resolveSheetName(array $sheetNames, ?string $requested, ?string $fallback): ?string
    {
        return $requested !== null && in_array($requested, $sheetNames, true) ? $requested : $fallback;
    }
}
