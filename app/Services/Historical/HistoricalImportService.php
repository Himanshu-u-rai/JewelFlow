<?php

namespace App\Services\Historical;

use App\Models\Customer;
use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalImportProfile;
use App\Models\Historical\HistoricalImportRow;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesLine;
use App\Models\Historical\HistoricalSalesPayment;
use App\Models\Shop;
use App\Models\ShopPaymentMethod;
use App\Support\Historical\HistoricalFields;
use App\Support\Historical\HistoricalMakingCharge;
use App\Support\Historical\HistoricalManualPublishRejected;
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
        private readonly HistoricalDocumentLifecycleService $lifecycle,
        private readonly HistoricalManualCalculationService $manualCalculations = new HistoricalManualCalculationService,
        private readonly HistoricalPaymentSettlementService $settlement = new HistoricalPaymentSettlementService,
        private readonly HistoricalCustomerMatcher $customerMatcher = new HistoricalCustomerMatcher,
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

        $batch = new HistoricalImportBatch;
        $batch->forceFill([
            'shop_id' => $shop->id,
            'historical_import_profile_id' => $profile?->id,
            'label' => $options['label'] ?? $file->getClientOriginalName(),
            'source_system' => $options['source_system'] ?? $profile?->source_system,
            'source_file_name' => $file->getClientOriginalName(),
            'cutover_date' => $options['cutover_date'] ?? null,
            'status' => HistoricalImportBatch::STATUS_DRAFT,
            'created_by' => $actorId,
            'source_file_disk' => self::DISK,
            'layout_type' => $profile?->layout_type,
            'date_format' => $profile?->date_format,
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

        $path = $this->absolutePath($batch);
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
                    'shop_id' => $batch->shop_id,
                    'historical_import_batch_id' => $batch->id,
                    // '' rather than NULL: the row uniqueness index is defeated by
                    // NULL-distinct semantics on single-sheet files.
                    'source_sheet' => (string) ($sheet ?? ''),
                    'source_row_number' => $row['row_number'],
                    'original_payload' => ['role' => $role] + self::stringify($row['cells']),
                    'severity' => HistoricalImportRow::SEVERITY_OK,
                    'validation_status' => HistoricalImportRow::VALIDATION_PENDING,
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

        $mapping = $profile->mapping ?? [];
        $decisions = $profile->column_decisions ?? [];
        $headers = $this->stagedHeaders($batch);

        $mappingMessages = $this->mapper->validate($mapping, $headers, $decisions, (string) $profile->layout_type);

        $groups = $this->group($batch, $profile, $mappingMessages);

        DB::transaction(function () use ($batch, $profile, $shop, $groups, $mapping, $actorId, $mappingMessages): void {
            $this->clearDrafts($batch);

            $documentCount = 0;

            foreach ($groups as $key => $group) {
                $messages = new HistoricalMessages;
                $messages->merge($mappingMessages);

                $headerCells = $this->mergeHeaderCells($group['header_rows'], $mapping, $messages);
                $lineCells = array_map(fn (array $row): array => $this->mapper->line($row['cells'], $mapping), $group['line_rows']);

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
                'layout_type' => $profile->layout_type,
                'date_format' => $profile->date_format,
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
            HistoricalImportProfile::LAYOUT_HEADER_ONLY => $this->groupOnePerRow($rows),
            HistoricalImportProfile::LAYOUT_SINGLE_ROW_PER_LINE => $this->groupByIdentity($rows, $mapping, $mappingMessages),
            HistoricalImportProfile::LAYOUT_HEADER_DETAIL => $this->groupHeaderDetail($rows, $mapping, $profile, $mappingMessages),
            default => throw new HistoricalParseException(
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
            $key = 'row:'.$row->source_row_number;

            $groups[$key] = [
                'header_rows' => [['id' => $row->id, 'cells' => $cells]],
                'line_rows' => [],
                'row_ids' => [$row->id],
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
            $cells = self::cellsOf($row);
            $header = $this->mapper->header($cells, $mapping);

            $number = trim((string) ($header['original_document_number'] ?? ''));
            $series = trim((string) ($header['document_series'] ?? ''));

            if ($number === '') {
                $mappingMessages->error(
                    'grouping_without_number',
                    sprintf(
                        'Row %d has no invoice number. When each row is one item line, the invoice number is what '
                        .'tells us which lines belong to the same bill.',
                        $row->source_row_number
                    ),
                    'original_document_number'
                );

                $number = '__row_'.$row->source_row_number;
            }

            $key = mb_strtoupper($series.'|'.$number, 'UTF-8');

            $groups[$key] ??= ['header_rows' => [], 'line_rows' => [], 'row_ids' => []];
            $groups[$key]['header_rows'][] = ['id' => $row->id, 'cells' => $cells];
            $groups[$key]['row_ids'][] = $row->id;

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
        $groups = [];
        $orphans = [];

        foreach ($rows as $row) {
            $cells = self::cellsOf($row);
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
                $groups[$key]['row_ids'][] = $row->id;

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
                $groups[$key]['row_ids'][] = $detail['id'];
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
                            .'Correct the source data before importing.',
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
        array $payments = [],
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

        $overrideKey = $resolution['override_key'] ?? null;
        $fingerprint = $this->normalizer->fingerprint($batch->shop_id, $attributes, $lines, $overrideKey);
        $conflict = $this->duplicates->detect($batch->shop_id, $attributes, $fingerprint);

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

        $document = new HistoricalSalesDocument;
        $document->forceFill($attributes + [
            'shop_id' => $batch->shop_id,
            'historical_import_batch_id' => $batch->id,
            'historical_reference' => (string) Str::uuid(),
            'status' => HistoricalSalesDocument::STATUS_DRAFT,
            'content_fingerprint' => $fingerprint,
            'duplicate_override_key' => $resolution['override_key'] ?? null,
            'imported_by' => $actorId,
            'imported_at' => now(),
        ])->save();

        foreach ($lines as $line) {
            $model = new HistoricalSalesLine;
            $model->forceFill($line + [
                'shop_id' => $batch->shop_id,
                'historical_sales_document_id' => $document->id,
            ])->save();
        }

        // Batch 3 §7/§8 — manual entry's payment rows only; a bulk file import
        // never passes $payments (default []), so this loop is a byte-for-byte
        // no-op for normalize()'s call site. Always via Eloquent, never a raw
        // insert, so HistoricalSalesPayment's own `saving` guards (tenant
        // ownership, mandatory account-label snapshot, the monotonic linked
        // marker) run for every row exactly as they do everywhere else.
        foreach ($payments as $payment) {
            // A linked row must carry its own real label — the model
            // deliberately refuses to invent one (see
            // HistoricalSalesPayment::normalizeAccountLabelSnapshot()) — so the
            // only place that can supply it is here, where the tenant-scoped
            // method id is still trustworthy input, not yet a foreign key.
            if (! empty($payment['shop_payment_method_id']) && empty($payment['account_label_snapshot'])) {
                $method = ShopPaymentMethod::withoutTenant()->find($payment['shop_payment_method_id']);
                $payment['account_label_snapshot'] = $method?->name;
            }

            $model = new HistoricalSalesPayment;
            $model->forceFill($payment + [
                'shop_id' => $batch->shop_id,
                'historical_sales_document_id' => $document->id,
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
                'severity' => $messages->severity(),
                'validation_status' => $messages->validationStatus(),
                'messages' => json_encode($messages->all()),
                'historical_sales_document_id' => $document?->id,
                // The key that ties a row to its stored duplicate_resolution, so the
                // review screen can offer skip/link/supersede/new-series per group.
                'grouping_key' => $groupingKey,
                'updated_at' => now(),
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
     * @param  array<int, array<string, mixed>>  $payments
     * @return array{batch: HistoricalImportBatch, document: ?HistoricalSalesDocument, messages: HistoricalMessages}
     */
    public function storeManual(Shop $shop, array $header, array $lines, int $actorId, array $options = [], array $payments = []): array
    {
        $calculated = $this->manualCalculations->prepare($header, $lines);
        $result = $this->normalizer->normalize(
            $shop,
            $calculated['header'],
            $calculated['lines'],
            $this->manualNormalizerOptions($options, $actorId)
        );

        $messages = $result['messages'];
        $normalizedLines = $this->applyLineCalculationState($result['lines'], $messages, $calculated);
        $attributes = array_replace($result['attributes'], $calculated['document_attributes']);
        $attributes = $this->applyPaymentSettlement($attributes, $payments, $options['paid_amount_mode'] ?? null, $messages);

        // The batch row's own creation used to happen here, BEFORE this
        // transaction opened. A fault thrown by persistDraft()/linkCustomer()
        // below rolled the document/lines/payments back correctly but left
        // that already-committed batch row behind as an orphaned, permanent
        // status=draft/no-document stray (discovered auditing the manual-entry
        // exception-disclosure fix). Creating it INSIDE this transaction closes
        // that gap: a fault now unwinds the batch too. Laravel/Postgres nest
        // this as a savepoint when storeManual() itself runs inside
        // publishManual()'s outer transaction, so that path — already provably
        // atomic — is unchanged.
        [$batch, $document] = DB::transaction(function () use ($shop, $header, $options, $actorId, $attributes, $normalizedLines, $messages, $payments): array {
            $batch = new HistoricalImportBatch;
            $batch->forceFill([
                'shop_id' => $shop->id,
                'label' => 'Manual entry — '.($header['original_document_number'] ?? 'unnumbered'),
                'source_system' => $options['source_system'] ?? self::SOURCE_MANUAL,
                'status' => HistoricalImportBatch::STATUS_DRAFT,
                'created_by' => $actorId,
                'cutover_date' => $options['cutover_date'] ?? null,
                'layout_type' => HistoricalImportProfile::LAYOUT_HEADER_ONLY,
                // The manual form uses a native date control, which submits ISO. There
                // is no ambiguity to resolve and therefore no format to choose.
                'date_format' => HistoricalDateParser::FORMAT_ISO,
                'row_count' => 1,
            ])->save();

            $document = $this->persistDraft(
                $batch,
                $attributes,
                $normalizedLines,
                $messages,
                'manual',
                $actorId,
                $payments
            );

            if ($document !== null && ! empty($options['customer_id'])) {
                $document = $this->lifecycle->linkCustomer($document, (int) $options['customer_id']);
            }

            return [$batch, $document];
        });

        $batch->forceFill([
            'document_count' => $document === null ? 0 : 1,
            'blocking_count' => $messages->countOf(HistoricalMessages::ERROR),
            'warning_count' => $messages->countOf(HistoricalMessages::WARNING),
            'preview_summary' => $this->manualPreviewSummary($batch, $messages),
            'preview_generated_at' => now(),
            'status' => HistoricalImportBatch::STATUS_REVIEW,
        ])->save();

        if ($document === null && $messages->hasBlocking()) {
            // Nothing was written apart from the empty batch; drop it rather than
            // leaving the operator a graveyard of failed attempts.
            $batch->delete();
        }

        return ['batch' => $batch, 'document' => $document, 'messages' => $messages];
    }

    /**
     * "Save & publish" for a manual bill: create the record and publish it in ONE
     * transaction, so the operator never has to visit the batch page.
     *
     * FAILURE SEMANTICS, stated exactly because half-published historical evidence
     * would be worse than no evidence at all:
     *
     *   - The whole method body runs inside a single DB::transaction. Creation and
     *     publication either both happen or neither does.
     *   - Every refusal (blocking finding, unresolved duplicate, unacknowledged or
     *     stale-acknowledged warnings, HIGH opening-balance overlap, an unclaimable
     *     batch) throws HistoricalManualPublishRejected, which unwinds the
     *     transaction. Row counts return to what they were: no batch, no document,
     *     no lines, no orphan draft.
     *   - An unexpected fault inside the lifecycle service propagates and unwinds
     *     the same way. `publish()` puts a stranded `publishing` claim back to
     *     `review` in its own finally; that write is itself rolled back with
     *     everything else, which is harmless precisely because the batch it refers
     *     to ceases to exist.
     *   - Therefore a failed direct publish is indistinguishable from never having
     *     pressed the button, and the operator's form comes back with the reason.
     *
     * The acknowledgement is verified against a digest recomputed HERE, from this
     * submission's warnings — never against whatever the preview screen happened to
     * be showing. See HistoricalMessages::warningDigest().
     *
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $payments
     * @return array{batch: HistoricalImportBatch, document: HistoricalSalesDocument, messages: HistoricalMessages}
     *
     * @throws HistoricalManualPublishRejected
     */
    public function publishManual(
        Shop $shop,
        array $header,
        array $lines,
        int $actorId,
        array $options = [],
        ?string $acknowledgedWarningDigest = null,
        array $payments = [],
    ): array {
        return DB::transaction(function () use ($shop, $header, $lines, $actorId, $options, $acknowledgedWarningDigest, $payments): array {
            // Full re-validation and re-normalisation from the raw input, not from
            // anything the preview computed. This is the same call Save-draft makes.
            $result = $this->storeManual($shop, $header, $lines, $actorId, $options, $payments);
            $batch = $result['batch'];
            $document = $result['document'];
            $messages = $result['messages'];

            if ($messages->hasBlocking()) {
                throw new HistoricalManualPublishRejected($this->firstMessageText($messages, HistoricalMessages::ERROR)
                    ?? 'This bill has blocking errors and cannot be published.');
            }

            // No document with no blocking error means the duplicate machinery
            // declined to create one (skip / unresolved collision). Nothing to publish.
            if ($document === null) {
                throw new HistoricalManualPublishRejected(
                    'This bill was not recorded — resolve the duplicate decision before publishing.'
                );
            }

            $digest = $messages->warningDigest();

            if ($digest !== null && $acknowledgedWarningDigest !== $digest) {
                throw new HistoricalManualPublishRejected(
                    'Acknowledge the outstanding warnings for this bill before publishing.'
                );
            }

            if ($digest !== null) {
                $batch->forceFill([
                    'warnings_acknowledged_at' => now(),
                    'warnings_acknowledged_by' => $actorId,
                ])->save();
            }

            // Batch 3 §B — customer creation/reuse, gated on the operator's own
            // explicit choice for THIS publish, never on the fuzzy suggestion
            // shown on screen (R1 stays intact). Still draft at this point, so
            // linkCustomer()'s draft-only guard and its own opening-balance
            // re-evaluation both run before blockedFromPublishing() below reads
            // that freshly-computed overlap.
            if ($document->customer_id === null) {
                $document = $this->applyCustomerOnPublish($shop, $document, $header, $options);
            }

            // The one publish gate, reused rather than reimplemented: already
            // published / not editable / no preview / blocking / unacknowledged
            // warnings / unresolved HIGH opening-balance overlap.
            $blocker = $batch->blockedFromPublishing();

            if ($blocker !== null) {
                throw new HistoricalManualPublishRejected($blocker);
            }

            $this->lifecycle->publish($batch, $actorId);

            return [
                'batch' => $batch->refresh(),
                'document' => $document->refresh(),
                'messages' => $messages,
            ];
        });
    }

    private function firstMessageText(HistoricalMessages $messages, string $severity): ?string
    {
        $first = $messages->ofSeverity($severity)[0] ?? null;

        return $first === null ? null : (string) $first['text'];
    }

    /** @return array<string, mixed> */
    private function manualNormalizerOptions(array $options, int $actorId): array
    {
        return [
            'date_format' => HistoricalDateParser::FORMAT_ISO,
            'decimal_separator' => '.',
            'thousands_separator' => ',',
            'tax_mode' => $options['tax_mode'] ?? HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'source_system' => $options['source_system'] ?? self::SOURCE_MANUAL,
            'cutover_date' => $options['cutover_date'] ?? null,
            // Manual entry never asks the operator to categorize the making
            // charge (no category select in the form) — default it so the
            // field still carries a sane, internally-supplied value.
            'making_category' => $options['making_category'] ?? HistoricalMakingCharge::CATEGORY_MAKING,
            'making_basis' => $options['making_basis'] ?? null,
            'zero_tax_confirmed' => (bool) ($options['zero_tax_confirmed'] ?? false),
            'cutover_acknowledged' => (bool) ($options['cutover_acknowledged'] ?? false),
            'cutover_reason' => $options['cutover_reason'] ?? null,
            'actor_id' => $actorId,
            'layout_type' => HistoricalImportProfile::LAYOUT_HEADER_ONLY,
        ];
    }

    /**
     * Batch 3 §4/§5/§9/§10 Section A — the manual-entry-only calculation
     * layer. Reads the raw Batch-3 fields StoreManualHistoricalRequest keeps
     * alive in each normalized line's `raw_payload` and turns them into a
     * persisted `calculation_state.metal_value` entry, or a blocking error
     * when the figure genuinely cannot be determined — never a silently
     * favourable default (foundation-audit D2).
     *
     * A line with neither `line_metal_type` nor `line_billable_weight_basis`
     * in its raw payload is left completely untouched. That is what keeps
     * bulk import and every pre-Batch-3 manual test byte-identical: neither
     * call site ever submits those fields, so this method is a no-op for them.
     *
     * @param  array<int, array<string, mixed>>  $lines  normalizer-output lines
     * @return array<int, array<string, mixed>>
     */
    private function applyLineCalculationState(array $lines, HistoricalMessages $messages, array $calculated): array
    {
        foreach ($calculated['errors'] as $error) {
            $messages->error($error['code'], $error['text'], 'lines');
        }

        foreach ($lines as $i => $line) {
            if (($calculated['line_attributes'][$i] ?? []) !== []) {
                $lines[$i] = array_replace($line, $calculated['line_attributes'][$i]);
            }
        }

        return $lines;
    }

    /**
     * Batch 3 §7/§8 Section B — payment-row settlement, manual-entry only.
     * Neither call site of normalize() (the file-import path) ever passes
     * `$payments`, and a bill with no payment rows and no manual override
     * leaves `$attributes['paid_amount_snapshot']` exactly as the normalizer
     * already set it from the header `paid_amount` field — this is what keeps
     * every pre-Batch-3 manual/import test byte-identical.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $payments
     * @return array<string, mixed>
     */
    private function applyPaymentSettlement(
        array $attributes,
        array $payments,
        ?string $paidAmountMode,
        HistoricalMessages $messages,
    ): array {
        if ($payments === [] && $paidAmountMode !== 'manual') {
            return $attributes;
        }

        $rowSum = $this->settlement->suggestedPaidTotal($payments);
        $typedPaidAmount = $attributes['paid_amount_snapshot'] ?? null;

        if ($paidAmountMode === 'manual' && $typedPaidAmount !== null) {
            $paidTotal = (float) $typedPaidAmount;

            if ($this->settlement->hasMismatch($rowSum, $paidTotal)) {
                $messages->warning(
                    'paid_amount_mismatch',
                    sprintf(
                        'The payment rows total ₹%s but the manually entered paid amount is ₹%s. '
                        .'Confirm this is correct before publishing.',
                        number_format($rowSum, 2),
                        number_format($paidTotal, 2)
                    ),
                    'paid_amount'
                );
            }
        } else {
            $paidTotal = $rowSum;
        }

        $settled = $this->settlement->settle((float) ($attributes['grand_total'] ?? 0), $paidTotal);

        $attributes['paid_amount_snapshot'] = $paidTotal;
        $attributes['outstanding_amount_snapshot'] = $settled['outstanding'];
        $attributes['advance_credit_amount'] = $settled['advance_credit'];

        return $attributes;
    }

    private static function rawText(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    // ------------------------------------------------- customer on publish

    /**
     * ponytail: exact denylist, not fuzzy matching — a historical bill that
     * literally says "Cash" or "Walk-in" is never a real, identifiable
     * customer no matter what mobile happens to be attached. Upgrade path if
     * this list proves too narrow: move it to a per-shop configurable list,
     * not a smarter string match.
     */
    private const GENERIC_CUSTOMER_NAMES = [
        'CASH', 'WALK-IN', 'WALKIN', 'WALK IN', 'COUNTER', 'COUNTER SALE',
        'CUSTOMER', 'N/A', 'NA', 'UNKNOWN', 'GENERAL', 'GENERAL CUSTOMER',
    ];

    private static function isGenericCustomerName(string $name): bool
    {
        return in_array(strtoupper(trim($name)), self::GENERIC_CUSTOMER_NAMES, true);
    }

    /** Same shape App\Rules\PanFormatRule enforces; duplicated because that
     *  class only knows how to fail a FormRequest, not answer a plain bool. */
    private static function validPan(mixed $pan): ?string
    {
        $pan = strtoupper(trim((string) $pan));

        return preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pan) === 1 ? $pan : null;
    }

    /**
     * Audit D2 — archived-only counterpart to HistoricalCustomerMatcher::byMobile(),
     * which is ->active()-only and so never sees this row. Same two-tier lookup
     * (exact canonical match, then a legacy-format fallback scan) but scoped to
     * $shopId explicitly since withoutTenant() bypasses the ambient TenantContext.
     */
    private static function archivedCustomerHoldingMobile(int $shopId, string $mobile): ?Customer
    {
        // ->archived() (ArchivableParty trait), not ->where('is_active', false) —
        // Postgres has no implicit bool/int cast and rejects the latter outright.
        $base = fn () => Customer::withoutTenant()->where('shop_id', $shopId)->archived();

        $exact = $base()->where('mobile', $mobile)->first();

        if ($exact !== null) {
            return $exact;
        }

        return $base()
            ->whereRaw('LENGTH(mobile) <> 10')
            ->get()
            ->first(fn (Customer $c): bool => HistoricalCustomerMatcher::normalizeMobile($c->mobile) === $mobile);
    }

    /**
     * Batch 3 §B — the ONLY place a historical publish may create or reuse a
     * live Customer. Gated entirely on `add_customer_on_publish`, submitted
     * with THIS publish request; a fuzzy suggestion rendered anywhere in the
     * UI is never consulted here, which is what keeps R1 (never auto-link on
     * a suggestion alone) intact. Called only from publishManual(), only
     * while $document is still draft, and only inside that method's single
     * DB::transaction — a later refusal in the same call unwinds this too.
     *
     * `HistoricalCustomerMatcher::byMobile()` is reused for the lookup
     * specifically because (unlike Customer::resolveByMobile(), which
     * silently picks ->first()) it surfaces every legacy-spelling duplicate
     * for the same canonical number — so more than one candidate is reported
     * here, not resolved.
     */
    private function applyCustomerOnPublish(
        Shop $shop,
        HistoricalSalesDocument $document,
        array $header,
        array $options,
    ): HistoricalSalesDocument {
        if (! (bool) ($options['add_customer_on_publish'] ?? false)) {
            return $document;
        }

        $snapshot = $document->customer_snapshot ?? [];
        $mobile = HistoricalCustomerMatcher::normalizeMobile($snapshot['mobile'] ?? null);
        $name = trim((string) ($snapshot['name'] ?? ''));

        // No canonical mobile, a blank name, or a Cash/Walk-in/generic name:
        // the bill still publishes, it just stays a snapshot — none of these
        // is an error. A blank name is deliberately its own check, not folded
        // into isGenericCustomerName() — an empty string is not "generic",
        // it is simply not an identity, and letting it fall through here used
        // to create a live customer literally named "Walk-in" (audit D1).
        if ($mobile === null || $name === '' || self::isGenericCustomerName($name)) {
            return $document;
        }

        $match = $this->customerMatcher->byMobile($shop->id, $mobile);

        if ($match['customers']->count() > 1) {
            throw new HistoricalManualPublishRejected(
                'More than one existing customer matches this mobile number. '
                .'Open the document and link the correct customer before publishing.'
            );
        }

        $customer = $match['customers']->first();

        // byMobile() is ->active()-only, so an archived customer sitting on
        // this exact canonical mobile is invisible to $match above — without
        // this check the code below falls into Customer::create() and hits
        // the (shop_id, mobile) unique index, a raw QueryException the
        // controller can only report as an unactionable "try again" (audit
        // D2). Checked BEFORE create, never reactivates or modifies the row.
        if ($customer === null) {
            $archived = self::archivedCustomerHoldingMobile($shop->id, $mobile);

            if ($archived !== null) {
                throw new HistoricalManualPublishRejected(
                    'A customer with this mobile already exists but is archived. '
                    .'Select or reactivate that customer before publishing.'
                );
            }
        }

        if ($customer === null) {
            $parts = $name !== '' ? preg_split('/\s+/', $name, 2) : [];

            $customer = Customer::create([
                // shop_id is not mass-assignable (BelongsToShop keeps it off
                // $fillable on purpose) — it comes from the ambient
                // TenantContext/Auth on the model's own creating() hook, same
                // as every other Customer::create() call in this codebase
                // (see Customer::findOrCreateByMobile()).
                'first_name' => $parts[0] ?? 'Walk-in',
                'last_name' => $parts[1] ?? null,
                'mobile' => $mobile,
                'address' => self::rawText($snapshot, 'address'),
                'pan' => self::validPan($snapshot['pan'] ?? null),
            ]);
        }

        return $this->lifecycle->linkCustomer($document, $customer->id);
    }

    /**
     * Zero-write preview of a manual entry. Same normalizer, same fingerprint,
     * same duplicate detector as storeManual() — nothing here opens a
     * transaction or calls persistDraft(). What the operator sees in preview
     * is provably what Save would compute, because it is the same call.
     *
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $payments
     * @return array{attributes: array, lines: array, messages: HistoricalMessages, fingerprint: ?string}
     */
    public function previewManual(Shop $shop, array $header, array $lines, int $actorId, array $options = [], array $payments = []): array
    {
        $calculated = $this->manualCalculations->prepare($header, $lines);
        $result = $this->normalizer->normalize(
            $shop,
            $calculated['header'],
            $calculated['lines'],
            $this->manualNormalizerOptions($options, $actorId)
        );

        $messages = $result['messages'];
        $normalizedLines = $this->applyLineCalculationState($result['lines'], $messages, $calculated);
        $attributes = array_replace($result['attributes'], $calculated['document_attributes']);
        $attributes = $this->applyPaymentSettlement($attributes, $payments, $options['paid_amount_mode'] ?? null, $messages);
        $fingerprint = null;

        if (! $messages->hasBlocking() && $attributes['grand_total'] !== null && $attributes['document_date'] !== null) {
            $fingerprint = $this->normalizer->fingerprint($shop->id, $attributes, $normalizedLines);
            $conflict = $this->duplicates->detect($shop->id, $attributes, $fingerprint);

            if ($conflict !== null) {
                $this->duplicates->report($conflict, $messages);
            }
        }

        return [
            'attributes' => $attributes,
            'lines' => $normalizedLines,
            'messages' => $messages,
            'fingerprint' => $fingerprint,
        ];
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

        // `messages` is cast to `array` on the model — already decoded, never a JSON string.
        [$counts, $codes] = $this->aggregateMessages((function () use ($rows) {
            foreach ($rows as $row) {
                foreach ($row->messages ?: [] as $message) {
                    yield $message;
                }
            }
        })());

        $summary = [
            'source_file' => $batch->source_file_name,
            'source_system' => $batch->source_system,
            'layout_type' => $batch->layout_type,
            'profile' => $profile?->name,
            'date_format' => $batch->date_format,
            'row_count' => $rows->count(),
        ]
            + $this->documentSummary($documents)
            + [
                'duplicate_count' => $this->countCode($codes, [
                    HistoricalDuplicateDetector::CODE_DUPLICATE_NUMBER,
                    HistoricalDuplicateDetector::CODE_DUPLICATE_FINGERPRINT,
                ]),
                'cutover_warnings' => $this->countCode($codes, [HistoricalDocumentNormalizer::CODE_DATE_AFTER_CUTOVER]),
                'blocking_count' => $counts['error'],
                'warning_count' => $counts['warning'],
                'informational_count' => $counts['info'],
                'ignored_columns' => array_keys(array_filter(
                    $profile?->column_decisions ?? [],
                    fn ($d) => $d === HistoricalImportProfile::DECISION_IGNORED
                )),
                'informational_columns' => array_keys(array_filter(
                    $profile?->column_decisions ?? [],
                    fn ($d) => $d === HistoricalImportProfile::DECISION_INFORMATIONAL
                )),
                'messages' => $codes,
            ];

        $batch->forceFill([
            'preview_summary' => $summary,
            'preview_generated_at' => now(),
            'blocking_count' => $counts['error'],
            'warning_count' => $counts['warning'],
            'status' => $batch->isEditable()
                ? HistoricalImportBatch::STATUS_REVIEW
                : $batch->status,
            // A changed preview invalidates a previous acknowledgement: the
            // operator acknowledged the OLD warnings, not these.
            'warnings_acknowledged_at' => null,
            'warnings_acknowledged_by' => null,
        ])->save();

        return $summary;
    }

    /**
     * Manual entry's preview summary. Same document/line-derived shape
     * refreshPreview() produces for a file import (documents, lines, totals,
     * dates, financial years, customers) — a manual bill is persisted through
     * the exact same HistoricalSalesDocument/HistoricalSalesLine rows, so
     * that half of the shape is computed identically.
     *
     * What differs is where the blocking/warning/info counts and message
     * codes come from: manual entry never creates HistoricalImportRow rows,
     * so refreshPreview()'s row-scan would find nothing and silently zero out
     * real findings. Here they come straight from the $messages the
     * normalizer + persistDraft() already built for this bill — the same
     * object storeManual() uses to decide document_count/blocking/warning.
     *
     * `row_count` is explicitly null, not 0: a manual bill legitimately has
     * no staged import rows, which is a different fact than "we found zero
     * rows in the data". The Blade layer decides how to present that.
     */
    private function manualPreviewSummary(HistoricalImportBatch $batch, HistoricalMessages $messages): array
    {
        $documents = HistoricalSalesDocument::query()
            ->where('historical_import_batch_id', $batch->id)
            ->with('lines:id,historical_sales_document_id')
            ->get();

        [$counts, $codes] = $this->aggregateMessages($messages->all());

        return [
            'source_file' => null,
            'source_system' => $batch->source_system,
            'layout_type' => $batch->layout_type,
            'profile' => null,
            'date_format' => $batch->date_format,
            'row_count' => null,
            'is_manual' => true,
            'manual' => true,
            'staged_rows_applicable' => false,
            'workflow_steps' => ['enter', 'preview', 'review', 'publish'],
            // The findings verbatim, not just aggregated code counts. A manual bill
            // has no HistoricalImportRow to carry its messages, and the document page
            // is now the only place they are shown — so they are kept whole here,
            // severity and field intact, for HistoricalMessages::fromArray().
            'manual_messages' => $messages->all(),
        ]
            + $this->documentSummary($documents)
            + [
                'duplicate_count' => $this->countCode($codes, [
                    HistoricalDuplicateDetector::CODE_DUPLICATE_NUMBER,
                    HistoricalDuplicateDetector::CODE_DUPLICATE_FINGERPRINT,
                ]),
                'cutover_warnings' => $this->countCode($codes, [HistoricalDocumentNormalizer::CODE_DATE_AFTER_CUTOVER]),
                'blocking_count' => $counts['error'],
                'warning_count' => $counts['warning'],
                'informational_count' => $counts['info'],
                'ignored_columns' => [],
                'informational_columns' => [],
                'messages' => $codes,
            ];
    }

    /**
     * The document/line-derived half of a preview summary — identical whether
     * the documents arrived via staged import rows or manual entry, since both
     * are just HistoricalSalesDocument rows linked by historical_import_batch_id.
     *
     * @param  \Illuminate\Support\Collection<int, HistoricalSalesDocument>  $documents
     * @return array<string, mixed>
     */
    private function documentSummary($documents): array
    {
        $dates = $documents->pluck('document_date')->filter()->sort()->values();

        return [
            'document_count' => $documents->count(),
            'line_count' => $documents->sum(fn ($d) => $d->lines->count()),
            'header_only_count' => $documents->filter(fn ($d) => $d->lines->isEmpty())->count(),
            'date_from' => $dates->first()?->toDateString(),
            'date_to' => $dates->last()?->toDateString(),
            'financial_years' => $documents->pluck('financial_year')->unique()->sort()->values()->all(),
            'customer_count' => $documents
                ->pluck('customer_snapshot')
                ->map(fn ($s) => is_array($s) ? ($s['name'] ?? null) : null)
                ->filter()->unique()->count(),
            'grand_total' => round((float) $documents->sum(fn ($d) => (float) $d->grand_total), 2),
            'taxable_total' => round((float) $documents->sum(fn ($d) => (float) $d->taxable_amount), 2),
            'tax_total' => round((float) $documents->sum(
                fn ($d) => (float) (($d->tax_snapshot['normalized']['tax_total'] ?? 0))
            ), 2),
            'cgst_total' => $this->taxComponentTotal($documents, 'cgst'),
            'sgst_total' => $this->taxComponentTotal($documents, 'sgst'),
            'igst_total' => $this->taxComponentTotal($documents, 'igst'),
            'cess_total' => $this->taxComponentTotal($documents, 'cess'),
            'paid_total' => round((float) $documents->sum(fn ($d) => (float) $d->paid_amount_snapshot), 2),
            'outstanding_total' => round((float) $documents->sum(fn ($d) => (float) $d->outstanding_amount_snapshot), 2),
            'tax_completeness' => $documents->countBy('tax_completeness')->all(),
            'making_mappings' => $documents
                ->map(fn ($d) => array_filter([
                    'label' => $d->making_label_original,
                    'category' => $d->making_category,
                    'basis' => $d->making_basis,
                ]))
                ->filter()->unique()->values()->all(),
            'samples' => $documents->take(5)->map(fn ($d) => [
                'number' => $d->displayNumber(),
                'date' => $d->document_date?->toDateString(),
                'customer' => $d->customer_snapshot['name'] ?? null,
                'grand_total' => (float) $d->grand_total,
                'tax' => $d->tax_completeness,
                'lines' => $d->lines->count(),
            ])->values()->all(),
        ];
    }

    /**
     * Tallies severities and groups by code — the shape refreshPreview() has
     * always exposed under 'messages'. Shared between the row-derived path
     * (file import) and the HistoricalMessages-derived path (manual entry) so
     * both produce identical output for identical inputs.
     *
     * @param  iterable<array{severity?: string, code?: string, text?: string}>  $messages
     * @return array{0: array{error: int, warning: int, info: int}, 1: array<string, array{severity: string, count: int, text: string}>}
     */
    private function aggregateMessages(iterable $messages): array
    {
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        $codes = [];

        foreach ($messages as $message) {
            $severity = (string) ($message['severity'] ?? HistoricalMessages::INFO);

            if (isset($counts[$severity])) {
                $counts[$severity]++;
            }

            $codes[(string) ($message['code'] ?? 'unknown')] ??= [
                'severity' => $severity,
                'count' => 0,
                'text' => (string) ($message['text'] ?? ''),
            ];
            $codes[(string) ($message['code'] ?? 'unknown')]['count']++;
        }

        return [$counts, $codes];
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
        $making = $profile->making_defaults ?? [];
        $taxes = $profile->tax_defaults ?? [];

        return [
            'date_format' => (string) $profile->date_format,
            'decimal_separator' => (string) ($profile->decimal_separator ?: '.'),
            'thousands_separator' => $profile->thousands_separator,
            'tax_mode' => (string) ($profile->tax_mode ?: HistoricalSalesDocument::TAX_MODE_UNKNOWN),
            'source_system' => $batch->source_system ?? $profile->source_system,
            'cutover_date' => $batch->cutover_date,
            'making_category' => $making['category'] ?? null,
            'making_basis' => $making['basis'] ?? null,
            'zero_tax_confirmed' => (bool) ($taxes['zero_confirmed'] ?? false),
            'cutover_acknowledged' => (bool) ($resolution['cutover_acknowledged'] ?? false),
            'cutover_reason' => $resolution['cutover_reason'] ?? null,
            'actor_id' => $actorId,
            'layout_type' => $profile->layout_type,
        ];
    }

    /** @return array<int, string> */
    /**
     * The union of every staged sheet's column headers. A header/detail import
     * (Layout C) stages two sheets with different columns — taking only the
     * first row's keys would see just the header sheet and wrongly flag every
     * detail-only mapped field (join key, item lines) as a missing column.
     */
    private function stagedHeaders(HistoricalImportBatch $batch): array
    {
        $headers = [];

        foreach (
            HistoricalImportRow::query()
                ->where('historical_import_batch_id', $batch->id)
                ->orderBy('id')
                ->get(['source_sheet', 'original_payload'])
                ->unique('source_sheet') as $row
        ) {
            $cells = $row->original_payload ?? [];
            unset($cells['role']);

            $headers += array_fill_keys(array_map('strval', array_keys($cells)), true);
        }

        return array_keys($headers);
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
                $value === null => null,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                is_scalar($value) => (string) $value,
                default => null,
            };
        }

        return $out;
    }
}
