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
     * Zero-width and invisible formatting characters. These carry no printed
     * meaning, so leaving them in would let `INV<ZWSP>1` and `INV1` coexist as
     * two "different" bills — a duplicate-detection bypass, not a distinction.
     * Removing them is the one deliberate deletion this class performs.
     */
    private const INVISIBLE = '/\p{Cf}/u';

    /** Every dash the real world prints for an ASCII hyphen. */
    private const DASHES = '/[\x{2010}-\x{2015}\x{2212}\x{FE58}\x{FE63}]/u';

    /** Any run of any Unicode space (NBSP, ideographic, tab, newline) -> one space. */
    private const SPACES = '/[\p{Zs}\s]+/u';

    /**
     * Comparison key for a printed document number.
     *
     * WHAT IT DOES, exactly and in this order:
     *   1. drop invisible formatting characters (see self::INVISIBLE);
     *   2. fold full-width forms U+FF01–U+FF5E onto their ASCII twins, so
     *      `００１２` and `0012` are the same number;
     *   3. fold Unicode dash variants onto ASCII `-`;
     *   4. collapse every Unicode whitespace run to one ordinary space and trim;
     *   5. Unicode-aware uppercase via mb_strtoupper (locale-independent).
     *
     * WHAT IT DELIBERATELY DOES NOT DO:
     *   - It does not strip punctuation. `INV-01` and `INV/01` are DIFFERENT
     *     printed numbers and stay different keys. The previous ASCII-only rule
     *     collapsed both to `INV01` and would have rejected the second bill as a
     *     duplicate of the first.
     *   - It does not drop non-ASCII letters or digits. Devanagari `०१२` and
     *     `बीजक/०१२` produce real keys; the old rule produced null, which
     *     disabled number-based duplicate protection for every non-Latin shop.
     *   - It does not collapse leading zeroes. `0012` and `12` are different
     *     printed numbers and may be different bills; merging them silently is
     *     worse than showing the operator two candidates.
     *   - It applies NO Unicode normalisation form (NFC/NFKC). `ext-intl` is not
     *     a declared requirement of this project, and a persisted, uniquely
     *     indexed identity key must be a pure function of its input — never of
     *     which extensions happen to be loaded. Every compatibility fold that
     *     matters for a printed invoice number (full-width digits and letters)
     *     is done explicitly above, so the result is identical on every runtime.
     *     Consequence, accepted knowingly: a canonically-decomposed spelling of
     *     a Devanagari number is a different key from its composed spelling.
     *     That fails toward "two candidates for operator review", never toward a
     *     silent merge.
     *
     * Returns null only when the value is null or contains nothing visible.
     */
    public static function normalizeNumber(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $normalized = self::fold($number);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * The shared, deterministic Unicode fold. Nothing here depends on ext-intl.
     */
    private static function fold(string $value): string
    {
        $value = preg_replace(self::INVISIBLE, '', $value) ?? $value;
        $value = self::foldFullWidth($value);
        $value = preg_replace(self::DASHES, '-', $value) ?? $value;
        $value = preg_replace(self::SPACES, ' ', $value) ?? $value;

        return mb_strtoupper(trim($value), 'UTF-8');
    }

    /**
     * U+FF01–U+FF5E are the full-width twins of ASCII 0x21–0x7E at a fixed
     * offset of 0xFEE0. A straight codepoint subtraction is exact and needs no
     * lookup table — this is the single piece of NFKC this class actually needs.
     * U+FF0D (full-width hyphen) lands on ASCII `-` for free.
     */
    private static function foldFullWidth(string $value): string
    {
        return preg_replace_callback(
            '/[\x{FF01}-\x{FF5E}]/u',
            static fn (array $m): string => mb_chr(mb_ord($m[0], 'UTF-8') - 0xFEE0, 'UTF-8'),
            $value
        ) ?? $value;
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

    /**
     * The LOOSE fold, for fingerprint fields only: series, customer name, line
     * description. Unlike a document number, these are free text a source system
     * punctuates however it likes, so punctuation and spacing are removed after
     * the shared Unicode fold — `Ramesh & Co.` and `RAMESH AND CO` are still not
     * equal, but `Ramesh & Co.` and `Ramesh & Co` are.
     *
     * It is Unicode-aware for the same reason normalizeNumber() is: the old
     * ASCII-only rule reduced EVERY Devanagari customer name to the empty
     * string, so two unrelated Hindi-named customers with the same date and
     * total collided on one fingerprint and the second import was refused as a
     * duplicate.
     */
    private static function comparable(?string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', self::fold((string) $value)) ?? '';
    }
}
