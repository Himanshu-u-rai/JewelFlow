<?php

namespace App\Services\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalImportProfile;
use App\Models\Historical\HistoricalImportRow;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesLine;
use App\Models\Shop;
use App\Support\Historical\HistoricalFields;
use App\Support\Historical\HistoricalMessages;
use App\Support\Historical\HistoricalParseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

/**
 * Drives one historical import from upload to reviewable draft.
 *
 *   upload -> stage -> map -> normalize -> validate -> duplicate review -> preview
 *
 * Nothing here publishes. Every write lands in historical_import_batches,
 * historical_import_rows, historical_sales_documents and historical_sales_lines
 * and nowhere else — no invoice, no counter, no ledger, no stock, no payment.
 *
 * OPERATIONAL MODEL (Release 1, chosen explicitly — Phase 7 option 2).
 * Parsing runs SYNCHRONOUSLY inside the request. This repository's
 * QUEUE_CONNECTION default is `sync`, and the only deployed worker unit consumes
 * the `ops-alerts` queue; dispatching historical imports onto a `historical-
 * imports` queue would create a job nothing runs and a batch that never leaves
 * "parsing". Capacity is therefore bounded honestly instead: CSV streams to
 * 50,000 rows, .xlsx is capped at 5,000 rows, and larger files are rejected with
 * instructions rather than accepted and quietly abandoned. Moving to a real queue
 * is a deployment change (a worker unit for `historical-imports`), not a code
 * change to this class.
 */
class HistoricalImportService
{
    public const DISK = 'local';

    public const SOURCE_MANUAL = 'Manual';

    public function __construct(
        private readonly HistoricalSourceFileReader $reader,
        private readonly HistoricalColumnMapper $mapper,
        private readonly HistoricalDocumentNormalizer $normalizer,
        private readonly HistoricalDuplicateDetector $duplicates,
    ) {}

    // ------------------------------------------------------------- uploading

    /**
     * Stores the workbook on the PRIVATE disk under a generated path and opens a
     * draft batch for it.
     *
     * The original filename is recorded as a label and never used as a path.
     * `../../.env` is a perfectly valid filename and a perfectly valid attack;
     * the storage path is `historical/{shop}/{batch}/{ulid}.{ext}` and contains
     * nothing the uploader chose.
     */
    public function createBatchFromUpload(
        Shop $shop,
        UploadedFile $file,
        ?HistoricalImportProfile $profile,
        int $actorId,
        array $options = [],
    ): HistoricalImportBatch {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, HistoricalSourceFileReader::SUPPORTED_EXTENSIONS, true)) {
            throw new HistoricalParseException(
                'Only .csv and .xlsx files can be imported. Re-save the file in one of those formats.'
            );
        }

        $batch = new HistoricalImportBatch();
        $batch->forceFill([
            'shop_id'                      => $shop->id,
            'historical_import_profile_id' => $profile?->id,
            'label'                        => $options['label'] ?? $file->getClientOriginalName(),
            'source_system'                => $options['source_system'] ?? $profile?->source_system,
            'source_file_name'             => $file->getClientOriginalName(),
            'cutover_date'                 => $options['cutover_date'] ?? null,
            'status'                       => HistoricalImportBatch::STATUS_DRAFT,
            'created_by'                   => $actorId,
            'source_file_disk'             => self::DISK,
            'layout_type'                  => $profile?->layout_type,
            'date_format'                  => $profile?->date_format,
        ])->save();

        $path = sprintf('historical/%d/%d/%s.%s', $shop->id, $batch->id, Str::ulid(), $extension);

        Storage::disk(self::DISK)->put($path, file_get_contents($file->getRealPath()));

        $batch->forceFill(['source_file_path' => $path])->save();

        return $batch->refresh();
    }

    public function absolutePath(HistoricalImportBatch $batch): string
    {
        if ($batch->source_file_path === null) {
            throw new HistoricalParseException('This batch has no uploaded source file.');
        }

        return Storage::disk($batch->source_file_disk ?? self::DISK)->path($batch->source_file_path);
    }

    public function extensionOf(HistoricalImportBatch $batch): string
    {
        return strtolower(pathinfo((string) $batch->source_file_path, PATHINFO_EXTENSION));
    }

    /** Sheet metadata for the mapping screen. */
    public function inspect(HistoricalImportBatch $batch): array
    {
        return $this->reader->inspect($this->absolutePath($batch), $this->extensionOf($batch));
    }

    /** @return array<int, string> */
    public function headers(HistoricalImportBatch $batch, ?string $sheet, int $headerRow): array
    {
        return $this->reader->headers($this->absolutePath($batch), $this->extensionOf($batch), $sheet, $headerRow);
    }

    /** Alias prefill for the mapping screen. Suggestion is never acceptance. */
    public function suggest(array $headers): array
    {
        return $this->mapper->suggest($headers);
    }

    /** A handful of real rows so the operator can see what a mapping produces. */
    public function sample(HistoricalImportBatch $batch, ?string $sheet, int $headerRow, array $headers): array
    {
        $rows = [];

        foreach ($this->reader->rows(
            $this->absolutePath($batch),
            $this->extensionOf($batch),
            $sheet,
            $headerRow,
            $headers,
            HistoricalSourceFileReader::SAMPLE_ROWS
        ) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    // --------------------------------------------------------------- staging

    /**
     * Copies the source file into staging rows, verbatim.
     *
     * Verbatim matters: `original_payload` is the audit trail, and re-running
     * normalization after a mapping correction must re-read what the file said,
     * not what a previous interpretation of it produced.
     */
    public function stage(HistoricalImportBatch $batch, HistoricalImportProfile $profile): int
    {
        $this->assertEditable($batch);

        $path      = $this->absolutePath($batch);
        $extension = $this->extensionOf($batch);
        $headerRow = (int) ($profile->header_row ?: 1);

        HistoricalImportRow::query()->where('historical_import_batch_id', $batch->id)->delete();

        $sheets = $profile->layout_type === HistoricalImportProfile::LAYOUT_HEADER_DETAIL
            ? array_filter(['header' => $profile->sheetFor('header'), 'detail' => $profile->sheetFor('detail')])
            : ['data' => $profile->sheetFor('data')];

        if ($profile->layout_type === HistoricalImportProfile::LAYOUT_HEADER_DETAIL && count($sheets) < 2) {
            throw new HistoricalParseException(
                'A header/detail import needs both the invoice sheet and the item sheet to be chosen.'
            );
        }

        $total = 0;

        foreach ($sheets as $role => $sheet) {
            $headers = $this->reader->headers($path, $extension, $sheet, $headerRow);

            foreach ($this->reader->rows($path, $extension, $sheet, $headerRow, $headers) as $row) {
                HistoricalImportRow::query()->create([
                    'shop_id'                    => $batch->shop_id,
                    'historical_import_batch_id' => $batch->id,
                    // '' rather than NULL: the row uniqueness index is defeated by
                    // NULL-distinct semantics on single-sheet files.
                    'source_sheet'               => (string) ($sheet ?? ''),
                    'source_row_number'          => $row['row_number'],
                    'original_payload'           => ['role' => $role] + self::stringify($row['cells']),
                    'severity'                   => HistoricalImportRow::SEVERITY_OK,
                    'validation_status'          => HistoricalImportRow::VALIDATION_PENDING,
                ]);

                $total++;
            }
        }

        $batch->forceFill(['row_count' => $total])->save();

        return $total;
    }

    // ----------------------------------------------------------- normalizing

    /**
     * Turns staged rows into draft documents. Idempotent by construction: every
     * run deletes the batch's draft documents first, so re-running after a
     * mapping fix produces the same archive it would have produced first time.
     */
    public function normalize(HistoricalImportBatch $batch, HistoricalImportProfile $profile, int $actorId): array
    {
        $this->assertEditable($batch);

        $shop = $batch->shop;

        if ($shop === null) {
            throw new LogicException("Historical import batch #{$batch->id} has no shop.");
        }

        $mapping   = $profile->mapping ?? [];
        $decisions = $profile->column_decisions ?? [];
        $headers   = $this->stagedHeaders($batch);

        $mappingMessages = $this->mapper->validate($mapping, $headers, $decisions, (string) $profile->layout_type);

        $groups = $this->group($batch, $profile, $mappingMessages);

        DB::transaction(function () use ($batch, $profile, $shop, $groups, $mapping, $actorId, $mappingMessages): void {
            $this->clearDrafts($batch);

            $documentCount = 0;

            foreach ($groups as $key => $group) {
                $messages = new HistoricalMessages();
                $messages->merge($mappingMessages);

                $headerCells = $this->mergeHeaderCells($group['header_rows'], $mapping, $messages);
                $lineCells   = array_map(fn (array $row): array => $this->mapper->line($row['cells'], $mapping), $group['line_rows']);

                $result = $this->normalizer->normalize(
                    $shop,
                    $this->mapper->header($headerCells, $mapping),
                    $lineCells,
                    $this->normalizerOptions($batch, $profile, $key, $actorId)
                );

                $messages->merge($result['messages']);

                $document = $this->persistDraft(
                    $batch,
                    $result['attributes'],
                    $result['lines'],
                    $messages,
                    $key,
                    $actorId
                );

                if ($document !== null) {
                    $documentCount++;
                }

                $this->applyRowOutcome($group, $messages, $document, $key);
            }

            $batch->forceFill([
                'document_count' => $documentCount,
                'layout_type'    => $profile->layout_type,
                'date_format'    => $profile->date_format,
            ])->save();
        });

        return $this->refreshPreview($batch, $profile);
    }

    /**
     * @return array<string, array{header_rows: array<int, array{id: int, cells: array}>,
     *                             line_rows: array<int, array{id: int, cells: array}>,
     *                             row_ids: array<int, int>, messages: HistoricalMessages}>
     */
    private function group(
        HistoricalImportBatch $batch,
        HistoricalImportProfile $profile,
        HistoricalMessages $mappingMessages,
    ): array {
        $mapping = $profile->mapping ?? [];

        $rows = HistoricalImportRow::query()
            ->where('historical_import_batch_id', $batch->id)
            ->orderBy('source_sheet')
            ->orderBy('source_row_number')
            ->get();

        return match ((string) $profile->layout_type) {
            HistoricalImportProfile::LAYOUT_HEADER_ONLY         => $this->groupOnePerRow($rows),
            HistoricalImportProfile::LAYOUT_SINGLE_ROW_PER_LINE => $this->groupByIdentity($rows, $mapping, $mappingMessages),
            HistoricalImportProfile::LAYOUT_HEADER_DETAIL       => $this->groupHeaderDetail($rows, $mapping, $profile, $mappingMessages),
            default                                             => throw new HistoricalParseException(
                'This import profile has no layout. Choose one row per invoice, one row per line item, or header/detail sheets.'
            ),
        };
    }

    /** Layout A. One row, one bill. */
    private function groupOnePerRow($rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $cells = self::cellsOf($row);
            $key   = 'row:' . $row->source_row_number;

            $groups[$key] = [
                'header_rows' => [['id' => $row->id, 'cells' => $cells]],
                'line_rows'   => [],
                'row_ids'     => [$row->id],
            ];
        }

        return $groups;
    }

    /**
     * Layout B. Rows collapse into one bill by CONFIRMED IDENTITY, never by
     * adjacency: an export sorted by item code interleaves two invoices, and
     * grouping on "the rows next to each other" silently merges them.
     */
    private function groupByIdentity($rows, array $mapping, HistoricalMessages $mappingMessages): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $cells  = self::cellsOf($row);
            $header = $this->mapper->header($cells, $mapping);

            $number = trim((string) ($header['original_document_number'] ?? ''));
            $series = trim((string) ($header['document_series'] ?? ''));

            if ($number === '') {
                $mappingMessages->error(
                    'grouping_without_number',
                    sprintf(
                        'Row %d has no invoice number. When each row is one item line, the invoice number is what '
                        . 'tells us which lines belong to the same bill.',
                        $row->source_row_number
                    ),
                    'original_document_number'
                );

                $number = '__row_' . $row->source_row_number;
            }

            $key = mb_strtoupper($series . '|' . $number, 'UTF-8');

            $groups[$key] ??= ['header_rows' => [], 'line_rows' => [], 'row_ids' => []];
            $groups[$key]['header_rows'][] = ['id' => $row->id, 'cells' => $cells];
            $groups[$key]['row_ids'][]     = $row->id;

            if ($this->mapper->hasLineData($cells, $mapping)) {
                $groups[$key]['line_rows'][] = ['id' => $row->id, 'cells' => $cells];
            }
        }

        return $groups;
    }

    /**
     * Layout C. Two sheets joined on an explicitly mapped key.
     *
     * A detail row whose key matches no header is an ORPHAN and blocks the batch.
     * Attaching it to "the nearest header" is exactly the guess that puts a
     * customer's necklace on somebody else's bill.
     */
    private function groupHeaderDetail(
        $rows,
        array $mapping,
        HistoricalImportProfile $profile,
        HistoricalMessages $mappingMessages,
    ): array {
        $headerSheet = (string) ($profile->sheetFor('header') ?? '');
        $groups      = [];
        $orphans     = [];

        foreach ($rows as $row) {
            $cells    = self::cellsOf($row);
            $isHeader = (string) $row->source_sheet === $headerSheet;

            $key = $this->mapper->joinKey(
                $cells,
                $mapping,
                $isHeader ? HistoricalFields::JOIN_KEY : HistoricalFields::DETAIL_JOIN_KEY
            );

            if ($key === null) {
                $mappingMessages->error(
                    'join_key_empty',
                    sprintf('Row %d of sheet "%s" has an empty link column, so it cannot be attached to an invoice.',
                        $row->source_row_number, $row->source_sheet),
                    HistoricalFields::JOIN_KEY
                );

                continue;
            }

            $key = mb_strtoupper($key, 'UTF-8');

            if ($isHeader) {
                $groups[$key] ??= ['header_rows' => [], 'line_rows' => [], 'row_ids' => []];
                $groups[$key]['header_rows'][] = ['id' => $row->id, 'cells' => $cells];
                $groups[$key]['row_ids'][]     = $row->id;

                continue;
            }

            $orphans[$key][] = ['id' => $row->id, 'cells' => $cells, 'row' => $row];
        }

        foreach ($orphans as $key => $details) {
            if (! isset($groups[$key])) {
                foreach ($details as $detail) {
                    $mappingMessages->error(
                        'orphan_detail_row',
                        sprintf(
                            'Row %d of sheet "%s" refers to invoice "%s", which is not on the invoice sheet.',
                            $detail['row']->source_row_number,
                            $detail['row']->source_sheet,
                            $key
                        ),
                        HistoricalFields::DETAIL_JOIN_KEY
                    );
                }

                continue;
            }

            foreach ($details as $detail) {
                $groups[$key]['line_rows'][] = ['id' => $detail['id'], 'cells' => $detail['cells']];
                $groups[$key]['row_ids'][]   = $detail['id'];
            }
        }

        return $groups;
    }

    /**
     * Layout B repeats the header on every line. When two rows of one bill
     * disagree about the customer or the total, one of them is wrong and we
     * cannot know which — so the bill blocks instead of silently taking the first.
     */
    private function mergeHeaderCells(array $headerRows, array $mapping, HistoricalMessages $messages): array
    {
        $merged = [];
        $source = [];

        foreach ($headerRows as $row) {
            foreach ($row['cells'] as $column => $value) {
                $text = is_scalar($value) ? trim((string) $value) : '';

                if ($text === '') {
                    continue;
                }

                if (! array_key_exists($column, $merged)) {
                    $merged[$column] = $value;
                    $source[$column] = $text;

                    continue;
                }

                if ($source[$column] !== $text && $this->isHeaderColumn($column, $mapping)) {
                    $messages->error(
                        'conflicting_repeated_header',
                        sprintf(
                            'The rows of this bill disagree about "%s": one says "%s", another says "%s". '
                            . 'Correct the source data before importing.',
                            $column,
                            $source[$column],
                            $text
                        ),
                        $column
                    );
                }
            }
        }

        return $merged;
    }

    private function isHeaderColumn(string $column, array $mapping): bool
    {
        foreach (HistoricalFields::HEADER as $field => $group) {
            if (($mapping[$field] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------- persisting

    /**
     * Writes one draft document, or nothing at all when the row cannot be a
     * document. A blocking error never produces a half-built record.
     */
    private function persistDraft(
        HistoricalImportBatch $batch,
        array $attributes,
        array $lines,
        HistoricalMessages $messages,
        string $groupingKey,
        int $actorId,
    ): ?HistoricalSalesDocument {
        $resolution = ($batch->duplicate_resolutions ?? [])[$groupingKey] ?? null;

        if (($resolution['action'] ?? null) === HistoricalDuplicateDetector::RESOLUTION_SKIP) {
            $messages->info('duplicate_skipped', 'This bill was skipped by the operator as a duplicate.', null);

            return null;
        }

        // An operator-confirmed distinct series is the ONLY thing that separates
        // two bills printed with the same number in one financial year.
        if (($resolution['action'] ?? null) === HistoricalDuplicateDetector::RESOLUTION_NEW_SERIES
            && ($resolution['series'] ?? '') !== '') {
            $attributes['document_series'] = (string) $resolution['series'];
        }

        if ($messages->hasBlocking() || $attributes['grand_total'] === null || $attributes['document_date'] === null) {
            return null;
        }

        $overrideKey  = $resolution['override_key'] ?? null;
        $fingerprint  = $this->normalizer->fingerprint($batch->shop_id, $attributes, $lines, $overrideKey);
        $conflict     = $this->duplicates->detect($batch->shop_id, $attributes, $fingerprint);

        if ($conflict !== null) {
            $resolved = $this->resolveConflict($conflict, $resolution, $attributes, $messages);

            if (! $resolved) {
                return null;
            }

            $fingerprint = $this->normalizer->fingerprint(
                $batch->shop_id,
                $attributes,
                $lines,
                $resolution['override_key'] ?? $overrideKey
            );
        }

        $document = new HistoricalSalesDocument();
        $document->forceFill($attributes + [
            'shop_id'                    => $batch->shop_id,
            'historical_import_batch_id' => $batch->id,
            'historical_reference'       => (string) Str::uuid(),
            'status'                     => HistoricalSalesDocument::STATUS_DRAFT,
            'content_fingerprint'        => $fingerprint,
            'duplicate_override_key'     => $resolution['override_key'] ?? null,
            'imported_by'                => $actorId,
            'imported_at'                => now(),
        ])->save();

        foreach ($lines as $line) {
            $model = new HistoricalSalesLine();
            $model->forceFill($line + [
                'shop_id'                       => $batch->shop_id,
                'historical_sales_document_id'  => $document->id,
            ])->save();
        }

        return $document;
    }

    /**
     * A collision is resolvable only in the ways Phase 12 allows.
     *
     * Note what is NOT here: an override key does not clear a number collision.
     * The number guard exists precisely so that bill INV/0045 cannot appear twice
     * in one financial year, and letting a free-text field switch it off would
     * make it decorative.
     */
    private function resolveConflict(
        array $conflict,
        ?array $resolution,
        array $attributes,
        HistoricalMessages $messages,
    ): bool {
        $action = $resolution['action'] ?? null;

        if ($conflict['type'] === HistoricalDuplicateDetector::CONFLICT_NUMBER) {
            if ($action === HistoricalDuplicateDetector::RESOLUTION_NEW_SERIES
                && $this->duplicates->seriesResolvesNumberConflict(
                    $attributes['document_series'] ?? null,
                    $conflict['existing']
                )) {
                $messages->info(
                    'duplicate_series_confirmed',
                    sprintf('Recorded as series "%s", confirmed as a different bill from %s.',
                        $attributes['document_series'], $conflict['existing']->displayNumber()),
                    'document_series'
                );

                return true;
            }

            $this->duplicates->report($conflict, $messages);

            return false;
        }

        // Fingerprint collision. The default is skip; supersede is a publish-level
        // act and is performed against the PUBLISHED archive, not silently here.
        if ($action === HistoricalDuplicateDetector::RESOLUTION_SUPERSEDE
            && ($resolution['override_key'] ?? '') !== '') {
            $messages->warning(
                'duplicate_supersede_requested',
                sprintf(
                    'This bill is recorded as a correction of %s. The original stays in the archive as superseded.',
                    $conflict['existing']->displayNumber()
                ),
                'grand_total'
            );

            return true;
        }

        $this->duplicates->report($conflict, $messages);

        return false;
    }

    private function applyRowOutcome(array $group, HistoricalMessages $messages, ?HistoricalSalesDocument $document, string $groupingKey): void
    {
        HistoricalImportRow::query()
            ->whereIn('id', $group['row_ids'])
            ->update([
                'severity'                     => $messages->severity(),
                'validation_status'            => $messages->validationStatus(),
                'messages'                     => json_encode($messages->all()),
                'historical_sales_document_id' => $document?->id,
                // The key that ties a row to its stored duplicate_resolution, so the
                // review screen can offer skip/link/supersede/new-series per group.
                'grouping_key'                 => $groupingKey,
                'updated_at'                   => now(),
            ]);
    }

    private function clearDrafts(HistoricalImportBatch $batch): void
    {
        HistoricalImportRow::query()
            ->where('historical_import_batch_id', $batch->id)
            ->update(['historical_sales_document_id' => null, 'updated_at' => now()]);

        HistoricalSalesDocument::query()
            ->where('historical_import_batch_id', $batch->id)
            ->where('status', HistoricalSalesDocument::STATUS_DRAFT)
            ->get()
            ->each
            ->delete();
    }

    // ----------------------------------------------------------------- manual

    /**
     * Manual entry. Converges on exactly the same normalizer, duplicate detector
     * and persistence path as a file import — a typed bill and an imported bill
     * are the same record, validated by the same rules.
     *
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{batch: HistoricalImportBatch, document: ?HistoricalSalesDocument, messages: HistoricalMessages}
     */
    public function storeManual(Shop $shop, array $header, array $lines, int $actorId, array $options = []): array
    {
        $batch = new HistoricalImportBatch();
        $batch->forceFill([
            'shop_id'       => $shop->id,
            'label'         => 'Manual entry — ' . ($header['original_document_number'] ?? 'unnumbered'),
            'source_system' => $options['source_system'] ?? self::SOURCE_MANUAL,
            'status'        => HistoricalImportBatch::STATUS_DRAFT,
            'created_by'    => $actorId,
            'cutover_date'  => $options['cutover_date'] ?? null,
            'layout_type'   => HistoricalImportProfile::LAYOUT_HEADER_ONLY,
            // The manual form uses a native date control, which submits ISO. There
            // is no ambiguity to resolve and therefore no format to choose.
            'date_format'   => HistoricalDateParser::FORMAT_ISO,
            'row_count'     => 1,
        ])->save();

        $result = $this->normalizer->normalize($shop, $header, $lines, [
            'date_format'          => HistoricalDateParser::FORMAT_ISO,
            'decimal_separator'    => '.',
            'thousands_separator'  => ',',
            'tax_mode'             => $options['tax_mode'] ?? HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'source_system'        => $options['source_system'] ?? self::SOURCE_MANUAL,
            'cutover_date'         => $options['cutover_date'] ?? null,
            'making_category'      => $options['making_category'] ?? null,
            'making_basis'         => $options['making_basis'] ?? null,
            'zero_tax_confirmed'   => (bool) ($options['zero_tax_confirmed'] ?? false),
            'cutover_acknowledged' => (bool) ($options['cutover_acknowledged'] ?? false),
            'cutover_reason'       => $options['cutover_reason'] ?? null,
            'actor_id'             => $actorId,
            'layout_type'          => HistoricalImportProfile::LAYOUT_HEADER_ONLY,
        ]);

        $messages = $result['messages'];

        $document = DB::transaction(fn (): ?HistoricalSalesDocument => $this->persistDraft(
            $batch,
            $result['attributes'],
            $result['lines'],
            $messages,
            'manual',
            $actorId
        ));

        $batch->forceFill([
            'document_count'  => $document === null ? 0 : 1,
            'blocking_count'  => $messages->countOf(HistoricalMessages::ERROR),
            'warning_count'   => $messages->countOf(HistoricalMessages::WARNING),
            'preview_summary' => ['manual' => true, 'messages' => $messages->all()],
            'preview_generated_at' => now(),
            'status'          => HistoricalImportBatch::STATUS_REVIEW,
        ])->save();

        if ($document === null && $messages->hasBlocking()) {
            // Nothing was written apart from the empty batch; drop it rather than
            // leaving the operator a graveyard of failed attempts.
            $batch->delete();
        }

        return ['batch' => $batch, 'document' => $document, 'messages' => $messages];
    }

    // ---------------------------------------------------------------- preview

    /**
     * The reconciliation preview. Everything an operator needs to decide whether
     * to publish, computed from what was actually staged — never from the file.
     */
    public function refreshPreview(HistoricalImportBatch $batch, ?HistoricalImportProfile $profile = null): array
    {
        $rows = HistoricalImportRow::query()
            ->where('historical_import_batch_id', $batch->id)
            ->get(['severity', 'validation_status', 'messages', 'historical_sales_document_id']);

        $documents = HistoricalSalesDocument::query()
            ->where('historical_import_batch_id', $batch->id)
            ->with('lines:id,historical_sales_document_id')
            ->get();

        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        $codes  = [];

        foreach ($rows as $row) {
            foreach (($row->messages ? json_decode($row->messages, true) : []) ?: [] as $message) {
                $severity = (string) ($message['severity'] ?? HistoricalMessages::INFO);

                if (isset($counts[$severity])) {
                    $counts[$severity]++;
                }

                $codes[(string) ($message['code'] ?? 'unknown')] ??= [
                    'severity' => $severity,
                    'count'    => 0,
                    'text'     => (string) ($message['text'] ?? ''),
                ];
                $codes[(string) ($message['code'] ?? 'unknown')]['count']++;
            }
        }

        $dates = $documents->pluck('document_date')->filter()->sort()->values();

        $summary = [
            'source_file'        => $batch->source_file_name,
            'source_system'      => $batch->source_system,
            'layout_type'        => $batch->layout_type,
            'profile'            => $profile?->name,
            'date_format'        => $batch->date_format,
            'row_count'          => $rows->count(),
            'document_count'     => $documents->count(),
            'line_count'         => $documents->sum(fn ($d) => $d->lines->count()),
            'header_only_count'  => $documents->filter(fn ($d) => $d->lines->isEmpty())->count(),
            'date_from'          => $dates->first()?->toDateString(),
            'date_to'            => $dates->last()?->toDateString(),
            'financial_years'    => $documents->pluck('financial_year')->unique()->sort()->values()->all(),
            'customer_count'     => $documents
                ->pluck('customer_snapshot')
                ->map(fn ($s) => is_array($s) ? ($s['name'] ?? null) : null)
                ->filter()->unique()->count(),
            'grand_total'        => round((float) $documents->sum(fn ($d) => (float) $d->grand_total), 2),
            'taxable_total'      => round((float) $documents->sum(fn ($d) => (float) $d->taxable_amount), 2),
            'tax_total'          => round((float) $documents->sum(
                fn ($d) => (float) (($d->tax_snapshot['normalized']['tax_total'] ?? 0))
            ), 2),
            'cgst_total'         => $this->taxComponentTotal($documents, 'cgst'),
            'sgst_total'         => $this->taxComponentTotal($documents, 'sgst'),
            'igst_total'         => $this->taxComponentTotal($documents, 'igst'),
            'cess_total'         => $this->taxComponentTotal($documents, 'cess'),
            'paid_total'         => round((float) $documents->sum(fn ($d) => (float) $d->paid_amount_snapshot), 2),
            'outstanding_total'  => round((float) $documents->sum(fn ($d) => (float) $d->outstanding_amount_snapshot), 2),
            'tax_completeness'   => $documents->countBy('tax_completeness')->all(),
            'making_mappings'    => $documents
                ->map(fn ($d) => array_filter([
                    'label'    => $d->making_label_original,
                    'category' => $d->making_category,
                    'basis'    => $d->making_basis,
                ]))
                ->filter()->unique()->values()->all(),
            'duplicate_count'    => $this->countCode($codes, [
                HistoricalDuplicateDetector::CODE_DUPLICATE_NUMBER,
                HistoricalDuplicateDetector::CODE_DUPLICATE_FINGERPRINT,
            ]),
            'cutover_warnings'   => $this->countCode($codes, [HistoricalDocumentNormalizer::CODE_DATE_AFTER_CUTOVER]),
            'blocking_count'     => $counts['error'],
            'warning_count'      => $counts['warning'],
            'informational_count' => $counts['info'],
            'ignored_columns'    => array_keys(array_filter(
                $profile?->column_decisions ?? [],
                fn ($d) => $d === HistoricalImportProfile::DECISION_IGNORED
            )),
            'informational_columns' => array_keys(array_filter(
                $profile?->column_decisions ?? [],
                fn ($d) => $d === HistoricalImportProfile::DECISION_INFORMATIONAL
            )),
            'messages'           => $codes,
            'samples'            => $documents->take(5)->map(fn ($d) => [
                'number'       => $d->displayNumber(),
                'date'         => $d->document_date?->toDateString(),
                'customer'     => $d->customer_snapshot['name'] ?? null,
                'grand_total'  => (float) $d->grand_total,
                'tax'          => $d->tax_completeness,
                'lines'        => $d->lines->count(),
            ])->values()->all(),
        ];

        $batch->forceFill([
            'preview_summary'      => $summary,
            'preview_generated_at' => now(),
            'blocking_count'       => $counts['error'],
            'warning_count'        => $counts['warning'],
            'status'               => $batch->isEditable()
                ? HistoricalImportBatch::STATUS_REVIEW
                : $batch->status,
            // A changed preview invalidates a previous acknowledgement: the
            // operator acknowledged the OLD warnings, not these.
            'warnings_acknowledged_at' => null,
            'warnings_acknowledged_by' => null,
        ])->save();

        return $summary;
    }

    private function taxComponentTotal($documents, string $component): float
    {
        return round((float) $documents->sum(
            fn ($d) => (float) (($d->tax_snapshot['source'][$component] ?? 0))
        ), 2);
    }

    private function countCode(array $codes, array $wanted): int
    {
        $total = 0;

        foreach ($wanted as $code) {
            $total += $codes[$code]['count'] ?? 0;
        }

        return $total;
    }

    // ------------------------------------------------------------------- misc

    private function normalizerOptions(
        HistoricalImportBatch $batch,
        HistoricalImportProfile $profile,
        string $groupingKey,
        int $actorId,
    ): array {
        $resolution = ($batch->duplicate_resolutions ?? [])[$groupingKey] ?? [];
        $making     = $profile->making_defaults ?? [];
        $taxes      = $profile->tax_defaults ?? [];

        return [
            'date_format'          => (string) $profile->date_format,
            'decimal_separator'    => (string) ($profile->decimal_separator ?: '.'),
            'thousands_separator'  => $profile->thousands_separator,
            'tax_mode'             => (string) ($profile->tax_mode ?: HistoricalSalesDocument::TAX_MODE_UNKNOWN),
            'source_system'        => $batch->source_system ?? $profile->source_system,
            'cutover_date'         => $batch->cutover_date,
            'making_category'      => $making['category'] ?? null,
            'making_basis'         => $making['basis'] ?? null,
            'zero_tax_confirmed'   => (bool) ($taxes['zero_confirmed'] ?? false),
            'cutover_acknowledged' => (bool) ($resolution['cutover_acknowledged'] ?? false),
            'cutover_reason'       => $resolution['cutover_reason'] ?? null,
            'actor_id'             => $actorId,
            'layout_type'          => $profile->layout_type,
        ];
    }

    /** @return array<int, string> */
    private function stagedHeaders(HistoricalImportBatch $batch): array
    {
        $row = HistoricalImportRow::query()
            ->where('historical_import_batch_id', $batch->id)
            ->orderBy('id')
            ->first();

        if ($row === null) {
            return [];
        }

        $cells = $row->original_payload ?? [];
        unset($cells['role']);

        return array_map('strval', array_keys($cells));
    }

    private function assertEditable(HistoricalImportBatch $batch): void
    {
        if (! $batch->isEditable()) {
            throw new LogicException(
                "Historical import batch #{$batch->id} is {$batch->status} and can no longer be changed."
            );
        }
    }

    private static function cellsOf(HistoricalImportRow $row): array
    {
        $cells = $row->original_payload ?? [];
        unset($cells['role']);

        return $cells;
    }

    /** @return array<string, string|null> */
    private static function stringify(array $cells): array
    {
        $out = [];

        foreach ($cells as $key => $value) {
            $out[(string) $key] = match (true) {
                $value === null                     => null,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                is_scalar($value)                   => (string) $value,
                default                             => null,
            };
        }

        return $out;
    }
}
