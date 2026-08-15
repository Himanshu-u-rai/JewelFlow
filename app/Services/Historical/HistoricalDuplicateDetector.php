<?php

namespace App\Services\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Support\Historical\HistoricalMessages;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds a bill that is already in the archive, and says so in a sentence a
 * jeweller can act on.
 *
 * TWO INDEPENDENT GUARDS, because each catches what the other cannot:
 *
 *   1. NUMBER IDENTITY — shop + financial year + document type + confirmed series
 *      + Unicode-normalized printed number. Catches the same bill re-keyed, even
 *      when a figure was mistyped the second time.
 *   2. CONTENT FINGERPRINT — the substance of the bill, deliberately excluding the
 *      source system. Catches the same bill arriving from Tally and then from
 *      Busy, and it is the ONLY guard for a cash bill with no printed number.
 *
 * The printed number is never changed to make an import succeed. There is no
 * auto-suffixing, no "-2", no silent renumbering. A collision is a question for
 * the operator, and the four answers are: skip it, link it, supersede it, or
 * declare a genuinely different series.
 *
 * `duplicate_override_key` deliberately does NOT bypass the number guard. It is
 * an input to the fingerprint only, so an operator cannot type an arbitrary
 * string and import bill INV/0045 twice into one financial year.
 */
class HistoricalDuplicateDetector
{
    public const CONFLICT_NUMBER      = 'number_identity';
    public const CONFLICT_FINGERPRINT = 'content_fingerprint';

    public const RESOLUTION_SKIP        = 'skip';
    public const RESOLUTION_LINK        = 'link';
    public const RESOLUTION_SUPERSEDE   = 'supersede';
    public const RESOLUTION_NEW_SERIES  = 'new_series';

    public const RESOLUTIONS = [
        self::RESOLUTION_SKIP,
        self::RESOLUTION_LINK,
        self::RESOLUTION_SUPERSEDE,
        self::RESOLUTION_NEW_SERIES,
    ];

    public const CODE_DUPLICATE_NUMBER      = 'duplicate_number_identity';
    public const CODE_DUPLICATE_FINGERPRINT = 'duplicate_content_fingerprint';

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{type: string, existing: HistoricalSalesDocument}|null
     */
    public function detect(
        int $shopId,
        array $attributes,
        string $fingerprint,
        ?int $ignoreDocumentId = null,
    ): ?array {
        $normalizedNumber = $attributes['original_document_number_normalized'] ?? null;

        if ($normalizedNumber !== null) {
            $existing = $this->live($shopId, $ignoreDocumentId)
                ->where('financial_year', $attributes['financial_year'])
                ->where('document_type', $attributes['document_type'])
                ->where('original_document_number_normalized', $normalizedNumber)
                // Mirrors COALESCE(upper(btrim(document_series)), '') in the unique
                // index. Application and database must agree on what "same series"
                // means, or one of them lets a duplicate through.
                ->whereRaw("COALESCE(upper(btrim(document_series)), '') = ?", [
                    self::comparableSeries($attributes['document_series'] ?? null),
                ])
                ->first();

            if ($existing !== null) {
                return ['type' => self::CONFLICT_NUMBER, 'existing' => $existing];
            }
        }

        $existing = $this->live($shopId, $ignoreDocumentId)
            ->where('content_fingerprint', $fingerprint)
            ->first();

        return $existing === null
            ? null
            : ['type' => self::CONFLICT_FINGERPRINT, 'existing' => $existing];
    }

    /**
     * Turns a collision into a blocking message the operator can act on.
     *
     * The raw database error for this is `SQLSTATE[23505] duplicate key value
     * violates unique constraint "historical_docs_number_identity_unique"`, which
     * tells a jeweller nothing and tells them to call support.
     */
    public function report(array $conflict, HistoricalMessages $messages): void
    {
        /** @var HistoricalSalesDocument $existing */
        $existing = $conflict['existing'];

        if ($conflict['type'] === self::CONFLICT_NUMBER) {
            $messages->error(
                self::CODE_DUPLICATE_NUMBER,
                sprintf(
                    'Bill %s is already recorded for %s%s (%s, total %s). The printed number cannot be changed to '
                    . 'import it again — skip this bill, or, if it is genuinely a different bill, enter and confirm '
                    . 'its document series.',
                    $existing->displayNumber(),
                    $existing->financial_year,
                    $existing->document_series ? ' series ' . $existing->document_series : '',
                    $existing->document_date?->toDateString() ?? 'no date',
                    number_format((float) $existing->grand_total, 2)
                ),
                'original_document_number'
            );

            return;
        }

        $messages->error(
            self::CODE_DUPLICATE_FINGERPRINT,
            sprintf(
                'An identical bill is already recorded: %s dated %s for %s. Same date, same total, same customer and '
                . 'same items — regardless of which system it was exported from. Skip it, or supersede the existing '
                . 'record if this one is the correction.',
                $existing->displayNumber(),
                $existing->document_date?->toDateString() ?? 'no date',
                number_format((float) $existing->grand_total, 2)
            ),
            'grand_total'
        );
    }

    /**
     * The series an operator typed to declare "this really is a different bill".
     *
     * Blank is not a declaration. Neither is the series the colliding document
     * already uses — that would put the import straight back into the same
     * collision while looking like it was resolved.
     */
    public function seriesResolvesNumberConflict(?string $series, HistoricalSalesDocument $existing): bool
    {
        $series = $series === null ? '' : self::comparableSeries($series);

        return $series !== ''
            && $series !== self::comparableSeries($existing->document_series);
    }

    private static function comparableSeries(?string $series): string
    {
        return mb_strtoupper(trim((string) $series), 'UTF-8');
    }

    /**
     * Void and superseded documents have released their number and their
     * fingerprint — that is the entire point of correcting by voiding. The
     * partial unique indexes use the same predicate.
     */
    private function live(int $shopId, ?int $ignoreDocumentId): Builder
    {
        return HistoricalSalesDocument::query()
            ->where('shop_id', $shopId)
            ->whereNotIn('status', [
                HistoricalSalesDocument::STATUS_VOID,
                HistoricalSalesDocument::STATUS_SUPERSEDED,
            ])
            ->when($ignoreDocumentId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreDocumentId));
    }
}
