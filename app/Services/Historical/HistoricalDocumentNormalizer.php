<?php

namespace App\Services\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Shop;
use App\Services\ShopPricingService;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\Historical\HistoricalMessages;
use App\Support\Historical\HistoricalMoney;
use App\Support\Historical\HistoricalParseException;
use Carbon\CarbonImmutable;

/**
 * The ONE place a historical bill becomes a record.
 *
 * Manual entry and CSV/XLSX import both arrive here with the same shape —
 * canonical field => raw value — so there is exactly one set of rules about what
 * a date means, when a total is impossible and how tax completeness is decided.
 * A second implementation for typed entry is how the two paths drift until an
 * operator can launder a rejected import through the manual form.
 *
 * Nothing in this class writes to the database, calls a pricing engine, consumes
 * a counter or touches an operational table. It reads values and returns
 * attributes plus messages.
 */
class HistoricalDocumentNormalizer
{
    public const CODE_DATE_MISSING       = 'date_missing';
    public const CODE_DATE_UNREADABLE    = 'date_unreadable';
    public const CODE_DATE_AMBIGUOUS     = 'date_ambiguous';
    public const CODE_DATE_FUTURE        = 'date_future';
    public const CODE_DATE_BEFORE_SHOP   = 'date_before_shop';
    public const CODE_DATE_AFTER_CUTOVER = 'date_after_cutover';
    public const CODE_TOTAL_MISSING      = 'grand_total_missing';
    public const CODE_TOTAL_UNREADABLE   = 'grand_total_unreadable';
    public const CODE_TOTAL_NEGATIVE     = 'grand_total_negative';
    public const CODE_HEADER_ONLY        = 'header_only_document';
    public const CODE_NUMBER_MISSING     = 'number_missing';
    public const CODE_NUMBER_NORMALIZED  = 'number_normalized';
    public const CODE_CUSTOMER_SNAPSHOT  = 'customer_snapshot_only';
    public const CODE_ITEM_SNAPSHOT      = 'item_snapshot_only';
    public const CODE_FORMULA_LITERAL    = 'formula_literal';
    public const CODE_LINE_TOTAL_DRIFT   = 'line_total_drift';
    public const CODE_LINE_TOTAL_UNKNOWN = 'line_total_unknown';
    public const CODE_OPENING_BALANCE    = 'opening_balance_not_linked';

    public function __construct(
        private readonly HistoricalDateParser $dates,
        private readonly HistoricalTaxNormalizer $tax,
        private readonly HistoricalMakingChargeNormalizer $making,
        private readonly ShopPricingService $pricing,
    ) {}

    /**
     * @param  array<string, mixed>  $header  canonical field => raw source value
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $options
     * @return array{attributes: array<string, mixed>, lines: array<int, array<string, mixed>>,
     *               messages: HistoricalMessages}
     */
    public function normalize(Shop $shop, array $header, array $lines, array $options): array
    {
        $messages = new HistoricalMessages();

        $decimal   = (string) ($options['decimal_separator'] ?? '.');
        $thousands = $options['thousands_separator'] ?? ',';
        $format    = (string) ($options['date_format'] ?? '');

        $date       = $this->resolveDate($shop, $header['document_date'] ?? null, $format, $options, $messages);
        $grandTotal = $this->resolveGrandTotal($header['grand_total'] ?? null, $decimal, $thousands, $messages);

        $amounts = $this->money($header, $decimal, $thousands, $messages);

        $taxResult = $this->tax->normalize(
            $amounts + ['grand_total' => $grandTotal ?? 0.0],
            (string) ($options['tax_mode'] ?? HistoricalSalesDocument::TAX_MODE_UNKNOWN),
            (bool) ($options['zero_tax_confirmed'] ?? false),
            $messages
        );

        $normalizedLines = $this->normalizeLines($lines, $decimal, $thousands, $options, $messages);

        if ($normalizedLines === []) {
            $messages->warning(
                self::CODE_HEADER_ONLY,
                'This bill is recorded from its header only — no item lines were supplied.',
                'lines'
            );
        }

        $making = $this->making->normalize(
            self::text($header['making_label'] ?? null),
            self::text($header['making_value'] ?? null),
            $options['making_category'] ?? null,
            $options['making_basis'] ?? null,
            [
                'quantity'    => null,
                'net_weight'  => null,
                'base_amount' => $amounts['metal_value'] ?? null,
            ],
            $messages,
            $decimal,
            $thousands
        );

        $number     = self::text($header['original_document_number'] ?? null);
        $normalized = HistoricalDocumentIdentity::normalizeNumber($number);
        $series     = self::text($header['document_series'] ?? null);

        if ($number === null) {
            $messages->warning(
                self::CODE_NUMBER_MISSING,
                'This bill has no printed invoice number. It is identified by its contents and an internal '
                . 'reference; no invoice number is generated for it.',
                'original_document_number'
            );
        } elseif ($normalized !== null && $normalized !== $number) {
            $messages->info(
                self::CODE_NUMBER_NORMALIZED,
                sprintf('"%s" is matched for duplicates as "%s". The printed number is stored unchanged.', $number, $normalized),
                'original_document_number'
            );
        }

        $customer = $this->customerSnapshot($header, $messages);

        // Batch 3 owns opening-balance reconciliation. Saying so explicitly beats
        // a silent `false` that reads as "checked and clear".
        $messages->info(
            self::CODE_OPENING_BALANCE,
            'Opening-balance overlap can only be checked once this bill is linked to a customer, after saving.',
            'opening_balance_overlap'
        );

        $documentDate = $date?->toDateString();

        $attributes = [
            'original_document_number'            => $number,
            'original_document_number_normalized' => $normalized,
            'document_series'                     => $series,
            'document_type'                       => HistoricalSalesDocument::TYPE_SALE_INVOICE,
            'document_date'                       => $documentDate,
            'financial_year'                      => $date ? HistoricalDocumentIdentity::financialYearFor($date) : null,
            'source_system'                       => self::text($options['source_system'] ?? null),
            'source_reference'                    => self::text($header['source_reference'] ?? null),

            'customer_snapshot'                   => $customer,
            'customer_gstin'                      => $customer['gstin'] ?? null,
            'place_of_supply'                     => $customer['place_of_supply'] ?? null,
            'shop_gstin_snapshot'                 => $shop->gst_number ?: null,

            'tax_mode'                            => $taxResult['mode'],
            'tax_completeness'                    => $taxResult['completeness'],
            'tax_snapshot'                        => $taxResult['snapshot'],

            'taxable_amount'                      => $amounts['taxable_amount'] ?? null,
            'discount_snapshot'                   => $amounts['discount'] ?? null,
            'rounding_snapshot'                   => $amounts['rounding'] ?? null,
            'grand_total'                         => $grandTotal,
            'paid_amount_snapshot'                => $amounts['paid_amount'] ?? null,
            'outstanding_amount_snapshot'         => $amounts['outstanding_amount'] ?? null,
            'metal_value'                         => $amounts['metal_value'] ?? null,
            'stone_value'                         => $amounts['stone_value'] ?? null,

            'making_label_original'               => $making['label_original'],
            'making_value_original'               => $making['value_original'],
            'making_category'                     => $making['category'],
            'making_basis'                        => $making['basis'],
            'making_amount'                       => $making['amount'],

            'opening_balance_overlap'             => false,
            'raw_payload'                         => [
                'header'  => self::scalarize($header),
                'making'  => $making,
                'options' => [
                    'date_format'         => $format,
                    'decimal_separator'   => $decimal,
                    'thousands_separator' => $thousands,
                    'tax_mode'            => $options['tax_mode'] ?? null,
                    'layout_type'         => $options['layout_type'] ?? null,
                ],
            ],
        ];

        $attributes = $this->applyCutoverAcknowledgement($attributes, $options, $messages);

        return ['attributes' => $attributes, 'lines' => $normalizedLines, 'messages' => $messages];
    }

    /**
     * Content fingerprint for the finished draft. Kept here so the importer and
     * the manual form cannot compute it two different ways.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function fingerprint(int $shopId, array $attributes, array $lines, ?string $overrideKey = null): string
    {
        return HistoricalDocumentIdentity::fingerprint(
            $shopId,
            (string) $attributes['document_type'],
            (string) $attributes['financial_year'],
            $attributes['document_series'] ?? null,
            $attributes['original_document_number_normalized'] ?? null,
            (string) $attributes['document_date'],
            (float) $attributes['grand_total'],
            $attributes['customer_snapshot']['name'] ?? null,
            $attributes['customer_snapshot']['mobile'] ?? null,
            array_map(static fn (array $line): array => [
                'description' => $line['source_description'] ?? null,
                'quantity'    => $line['quantity'] ?? 0,
                'line_total'  => $line['line_total'] ?? 0,
            ], $lines),
            $overrideKey
        );
    }

    // ------------------------------------------------------------------ dates

    /**
     * Phase 4, in one method, because a document date is the single field that
     * can silently move a year of revenue.
     */
    private function resolveDate(
        Shop $shop,
        mixed $raw,
        string $format,
        array $options,
        HistoricalMessages $messages,
    ): ?CarbonImmutable {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            $messages->error(self::CODE_DATE_MISSING, 'This bill has no date.', 'document_date');

            return null;
        }

        if ($format === '') {
            $messages->error(
                self::CODE_DATE_AMBIGUOUS,
                sprintf(
                    'No date format has been chosen for this import, and "%s" cannot be read without one. '
                    . 'Choose DD/MM/YYYY, MM/DD/YYYY, YYYY-MM-DD or Excel serial on the mapping screen.',
                    is_scalar($raw) ? (string) $raw : 'this value'
                ),
                'document_date'
            );

            return null;
        }

        try {
            $date = $this->dates->parse($raw, $format);
        } catch (HistoricalParseException $e) {
            $messages->error(self::CODE_DATE_UNREADABLE, $e->getMessage(), 'document_date');

            return null;
        }

        // The shop's business date, not the server's: an import running at 00:30
        // IST on a server in UTC must not treat yesterday's bill as tomorrow's.
        $businessDate = $this->pricing->businessDate($shop);

        if ($date->greaterThan($businessDate)) {
            $messages->error(
                self::CODE_DATE_FUTURE,
                sprintf(
                    'This bill is dated %s, which is after the shop\'s current business date (%s). '
                    . 'A historical record cannot be in the future.',
                    $date->toDateString(),
                    $businessDate->toDateString()
                ),
                'document_date'
            );

            return $date;
        }

        $shopCreated = $shop->created_at;

        if ($shopCreated !== null && $date->lessThan(CarbonImmutable::instance($shopCreated)->startOfDay())) {
            // Expected and legitimate: this module exists to record bills from
            // before JewelFlow. Surfaced so a typo'd year is still noticed.
            $messages->warning(
                self::CODE_DATE_BEFORE_SHOP,
                sprintf(
                    'This bill is dated %s, before this shop was set up in JewelFlow (%s). That is normal for '
                    . 'historical data — check the year is right.',
                    $date->toDateString(),
                    CarbonImmutable::instance($shopCreated)->toDateString()
                ),
                'document_date'
            );
        }

        $cutover = $options['cutover_date'] ?? null;

        if ($cutover !== null && $date->greaterThan(CarbonImmutable::parse($cutover)->startOfDay())) {
            $messages->warning(
                self::CODE_DATE_AFTER_CUTOVER,
                sprintf(
                    'This bill is dated %s, after the shop went live on JewelFlow (%s). Importing it as history '
                    . 'requires an acknowledgement and a reason.',
                    $date->toDateString(),
                    CarbonImmutable::parse($cutover)->toDateString()
                ),
                'document_date'
            );
        }

        return $date;
    }

    /**
     * A cutover warning is only acknowledged when a human said why. The reason is
     * stored on the document; the actor and the moment are stored beside it, so
     * "who allowed this" is answerable years later.
     */
    private function applyCutoverAcknowledgement(
        array $attributes,
        array $options,
        HistoricalMessages $messages,
    ): array {
        if (! $messages->has(self::CODE_DATE_AFTER_CUTOVER)) {
            return $attributes;
        }

        $reason = self::text($options['cutover_reason'] ?? null);
        $actor  = $options['actor_id'] ?? null;

        if (! ($options['cutover_acknowledged'] ?? false) || $reason === null || $actor === null) {
            return $attributes;
        }

        return $attributes + [
            'cutover_warning_acknowledged'     => true,
            'cutover_warning_reason'           => $reason,
            'cutover_warning_acknowledged_by'  => (int) $actor,
            'cutover_warning_acknowledged_at'  => now(),
        ];
    }

    // ----------------------------------------------------------------- money

    private function resolveGrandTotal(
        mixed $raw,
        string $decimal,
        ?string $thousands,
        HistoricalMessages $messages,
    ): ?float {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            $messages->error(
                self::CODE_TOTAL_MISSING,
                'This bill has no total. A historical sale without its total cannot be recorded.',
                'grand_total'
            );

            return null;
        }

        try {
            $total = HistoricalMoney::parse($raw, $decimal, $thousands);
        } catch (HistoricalParseException $e) {
            $messages->error(self::CODE_TOTAL_UNREADABLE, $e->getMessage(), 'grand_total');

            return null;
        }

        if ($total < 0) {
            $messages->error(
                self::CODE_TOTAL_NEGATIVE,
                'This bill has a negative total. Credit notes and returns are not part of this release.',
                'grand_total'
            );
        }

        return $total;
    }

    /**
     * @return array<string, float|null>
     */
    private function money(array $header, string $decimal, ?string $thousands, HistoricalMessages $messages): array
    {
        $fields = [
            'taxable_amount', 'tax_total', 'cgst', 'sgst', 'igst', 'cess',
            'discount', 'rounding', 'metal_value', 'stone_value',
            'paid_amount', 'outstanding_amount',
        ];

        $values = [];

        foreach ($fields as $field) {
            $raw = $header[$field] ?? null;

            if (HistoricalSourceFileReader::looksLikeFormula($raw)) {
                $messages->info(
                    self::CODE_FORMULA_LITERAL,
                    sprintf('"%s" in %s looks like a spreadsheet formula. It is stored as text, never evaluated.', $raw, $field),
                    $field
                );
            }

            try {
                $values[$field] = HistoricalMoney::parseOptional($raw, $decimal, $thousands);
            } catch (HistoricalParseException $e) {
                // Not blocking on its own: an unreadable optional amount stays
                // unknown, and the total reconciliation below will notice if that
                // unknown actually mattered.
                $messages->warning('amount_unreadable', $e->getMessage(), $field);
                $values[$field] = null;
            }
        }

        return $values;
    }

    // ----------------------------------------------------------------- lines

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLines(
        array $lines,
        string $decimal,
        ?string $thousands,
        array $options,
        HistoricalMessages $messages,
    ): array {
        $normalized = [];
        $number     = 0;

        foreach ($lines as $line) {
            $number++;

            $quantity  = self::number($line['line_quantity'] ?? null, $decimal, $thousands);
            $netWeight = self::number($line['line_net_weight'] ?? null, $decimal, $thousands);
            $lineTotal = self::number($line['line_total'] ?? null, $decimal, $thousands);
            $rate      = self::number($line['line_rate'] ?? null, $decimal, $thousands);

            $lineMaking = $this->making->normalize(
                self::text($line['line_making_label'] ?? null),
                self::text($line['line_making_value'] ?? null),
                $options['making_category'] ?? null,
                $options['making_basis'] ?? null,
                [
                    'quantity'    => $quantity,
                    'net_weight'  => $netWeight,
                    'base_amount' => self::number($line['line_metal_value'] ?? null, $decimal, $thousands),
                ],
                $messages,
                $decimal,
                $thousands
            );

            $name = self::text($line['line_item_name'] ?? null);

            // `historical_sales_lines.line_total` is NOT NULL with a `>= 0` check
            // (Batch 1 schema, immutable) — a line with no mapped/parseable total
            // (e.g. a Layout C detail row that only carries an item name) still
            // has to persist a number. 0 is the same "unknown, not invented"
            // convention fingerprint() already uses; the message makes the
            // substitution visible instead of silently understating the line.
            if ($lineTotal === null) {
                $messages->warning(
                    self::CODE_LINE_TOTAL_UNKNOWN,
                    sprintf('Line %d has no readable total. Recorded as 0 — the header grand total is unaffected.', $number),
                    'lines'
                );
                $lineTotal = 0.0;
            }

            $normalized[] = [
                'line_number'           => $number,
                // No items.id, ever. Batch 2 creates no catalogue row and moves no
                // stock: what the bill said the item was IS the record.
                'item_id'               => null,
                'item_snapshot'         => array_filter([
                    'name'   => $name,
                    'sku'    => self::text($line['line_sku'] ?? null),
                    'hsn'    => self::text($line['line_hsn'] ?? null),
                    'purity' => self::text($line['line_purity'] ?? null),
                ], static fn ($v) => $v !== null),
                'source_sku'            => self::text($line['line_sku'] ?? null),
                'source_description'    => $name,
                'hsn_snapshot'          => self::text($line['line_hsn'] ?? null),
                'quantity'              => $quantity,
                'gross_weight'          => self::number($line['line_gross_weight'] ?? null, $decimal, $thousands),
                'net_weight'            => $netWeight,
                'stone_weight'          => self::number($line['line_stone_weight'] ?? null, $decimal, $thousands),
                'purity_snapshot'       => self::text($line['line_purity'] ?? null),
                'metal_snapshot'        => null,
                'stone_snapshot'        => null,
                'making_label_original' => $lineMaking['label_original'],
                'making_value_original' => $lineMaking['value_original'],
                'making_category'       => $lineMaking['category'],
                'making_basis'          => $lineMaking['basis'],
                'making_amount'         => $lineMaking['amount'],
                'rate_snapshot'         => $rate,
                'line_total'            => $lineTotal,
                'raw_payload'           => self::scalarize($line),
            ];
        }

        if ($normalized !== []) {
            $messages->info(
                self::CODE_ITEM_SNAPSHOT,
                'Item lines are stored as snapshots of the original bill. No catalogue item is created and no stock moves.',
                'lines'
            );
        }

        return $normalized;
    }

    // ----------------------------------------------------------------- party

    private function customerSnapshot(array $header, HistoricalMessages $messages): array
    {
        $snapshot = array_filter([
            'name'            => self::text($header['customer_name'] ?? null),
            'mobile'          => self::text($header['customer_mobile'] ?? null),
            'gstin'           => self::text($header['customer_gstin'] ?? null),
            'address'         => self::text($header['customer_address'] ?? null),
            'place_of_supply' => self::text($header['place_of_supply'] ?? null),
        ], static fn ($v) => $v !== null);

        if ($snapshot !== []) {
            $messages->info(
                self::CODE_CUSTOMER_SNAPSHOT,
                'The customer is stored as a snapshot of the original bill. No JewelFlow customer is created or linked.',
                'customer_name'
            );
        }

        return $snapshot;
    }

    // ------------------------------------------------------------------ util

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function number(mixed $value, string $decimal, ?string $thousands): ?float
    {
        try {
            return HistoricalMoney::parseOptional($value, $decimal, $thousands);
        } catch (HistoricalParseException) {
            return null;
        }
    }

    /** @return array<string, string|null> */
    private static function scalarize(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            $out[(string) $key] = match (true) {
                $value === null   => null,
                is_scalar($value) => (string) $value,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                default           => null,
            };
        }

        return $out;
    }
}
