<?php

namespace App\Support\Historical;

use DateTimeInterface;

/**
 * Identity rules for historical sales documents: how the client's printed
 * invoice number is normalized for search/duplicate detection, how a document
 * date maps to an Indian financial year, and how a document's CONTENT is
 * fingerprinted.
 *
 * Nothing here ever changes what is displayed. `normalizeNumber()` produces a
 * comparison key only; the exact printed number lives untouched in
 * `historical_sales_documents.original_document_number`.
 */
final class HistoricalDocumentIdentity
{
    /**
     * Comparison key for a printed document number.
     *
     * Uppercases and strips separators/whitespace so `INV/2023-24/0045` and
     * `INV-2023 24 0045` are recognised as the same bill.
     *
     * LEADING ZEROES ARE DELIBERATELY PRESERVED. `0012` and `12` are different
     * printed numbers and may be different bills; collapsing them would silently
     * merge two real invoices, which is worse than showing the operator two
     * candidates. Returns null when nothing comparable survives.
     */
    public static function normalizeNumber(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper($number)) ?? '';

        return $normalized === '' ? null : $normalized;
    }

    /** Indian financial year (1 April – 31 March) as `2023-24`. */
    public static function financialYearFor(DateTimeInterface $date): string
    {
        $year  = (int) $date->format('Y');
        $month = (int) $date->format('n');

        $startYear = $month >= 4 ? $year : $year - 1;

        return $startYear . '-' . str_pad((string) (($startYear + 1) % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Content identity for a historical document.
     *
     * Detects the SAME PHYSICAL BILL arriving twice — re-uploaded file, second
     * export, a different accounting package. It therefore deliberately excludes
     * `source_system`, the batch, the row trail and every surrogate id: owner
     * rule — provenance alone must not make an otherwise identical document
     * unique.
     *
     * Unnumbered documents lean entirely on this, which is why the line detail
     * participates: two cash bills on the same day for the same amount are
     * indistinguishable by header alone.
     *
     * `duplicateOverrideKey` is the ONLY escape. It is set exclusively when an
     * operator explicitly confirms two content-identical documents are genuinely
     * distinct bills. There is no automatic suffixing and no silent renumbering.
     *
     * @param  array<int, array{description?: ?string, quantity?: mixed, line_total?: mixed}>  $lines
     */
    public static function fingerprint(
        int $shopId,
        string $documentType,
        string $financialYear,
        ?string $documentSeries,
        ?string $normalizedNumber,
        string $documentDate,
        float $grandTotal,
        ?string $customerName,
        ?string $customerMobile,
        array $lines = [],
        ?string $duplicateOverrideKey = null,
    ): string {
        $lineParts = array_map(static function (array $line): string {
            return implode('|', [
                self::comparable($line['description'] ?? null),
                number_format((float) ($line['quantity'] ?? 0), 3, '.', ''),
                number_format((float) ($line['line_total'] ?? 0), 2, '.', ''),
            ]);
        }, $lines);

        // Sorted: source systems export line order inconsistently, and a
        // re-ordered export is the same bill.
        sort($lineParts, SORT_STRING);

        $payload = implode("\n", [
            'shop:' . $shopId,
            'type:' . $documentType,
            'fy:' . $financialYear,
            'series:' . self::comparable($documentSeries),
            'number:' . ($normalizedNumber ?? ''),
            'date:' . $documentDate,
            'total:' . number_format($grandTotal, 2, '.', ''),
            'customer:' . self::comparable($customerName),
            'mobile:' . preg_replace('/\D/', '', (string) $customerMobile),
            'lines:' . count($lineParts),
            'override:' . ($duplicateOverrideKey ?? ''),
            ...$lineParts,
        ]);

        return hash('sha256', $payload);
    }

    private static function comparable(?string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $value)) ?? '';
    }
}
