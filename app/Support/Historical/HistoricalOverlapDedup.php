<?php

namespace App\Support\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use Illuminate\Support\Collection;

/**
 * Classifies historical documents by their `opening_balance_overlap` /
 * `opening_balance_resolution` state (Batch 4 requirements doc §4/§9.2).
 * Never re-derives or writes a resolution — purely routes each document to
 * the bucket its EXISTING columns already dictate.
 *
 * **Superseded (owner-agreed reporting semantics, see `DuesAgingDataset`
 * docblock): this is disclosure-only, not exclusion.** HISTORICAL mode never
 * reads `CustomerOpeningBalance` and never adds one to this report, so an
 * overlap flag is never grounds to subtract a historical snapshot amount —
 * every bucket below is counted in full. The classification exists solely so
 * the report can surface *how many* documents carry each classification as a
 * visible warning ("this bill may also be reflected in the customer's opening
 * balance — not netted out here"), never a silent drop and never a guess.
 * Numeric reconciliation against `CustomerOpeningBalance` remains deferred
 * (owner decisions §7.5/§7.6, still unresolved) — this class does not attempt
 * it and nothing reads these buckets to exclude anything.
 */
final class HistoricalOverlapDedup
{
    /**
     * @param  iterable<HistoricalSalesDocument>  $documents
     * @return array{noOverlap: Collection<int, HistoricalSalesDocument>, separate: Collection<int, HistoricalSalesDocument>, included: Collection<int, HistoricalSalesDocument>, unresolved: Collection<int, HistoricalSalesDocument>}
     */
    public static function partition(iterable $documents): array
    {
        $noOverlap = new Collection;
        $separate = new Collection;
        $included = new Collection;
        $unresolved = new Collection;

        foreach ($documents as $document) {
            if (! $document->opening_balance_overlap) {
                $noOverlap->push($document);

                continue;
            }

            match ($document->opening_balance_resolution) {
                HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE => $separate->push($document),
                HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_INCLUDED => $included->push($document),
                default => $unresolved->push($document), // NULL — flagged but never resolved by an operator
            };
        }

        return [
            'noOverlap' => $noOverlap,
            'separate' => $separate,
            'included' => $included,
            'unresolved' => $unresolved,
        ];
    }
}
