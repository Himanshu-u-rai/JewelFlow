<?php

namespace App\Support\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use Illuminate\Support\Collection;

/**
 * The §4 duplicate/overlap dedup rule (Batch 4 requirements doc §4/§9.2), as a
 * small pure function: documents in, three buckets out. Never re-derives or
 * writes a resolution — that decision was already made (or explicitly left
 * unresolved) at the document level; this only routes each document to the
 * bucket its EXISTING `opening_balance_overlap`/`opening_balance_resolution`
 * columns already dictate.
 *
 * Shared by every read model that must avoid double-counting a historical
 * document already reflected elsewhere (Dues Aging HISTORICAL/COMBINED
 * modes today; anything else that aggregates historical receivables later).
 *
 *   additive                    — no overlap, or overlap resolved SEPARATE.
 *                                  Safe to add to a receivables total.
 *   excludedIncludedInOpening   — overlap resolved INCLUDED: already counted
 *                                  inside CustomerOpeningBalance elsewhere.
 *                                  Adding it again would double the debt.
 *   excludedUnresolved          — overlap flagged but never resolved. Never
 *                                  guessed into either bucket above; excluded
 *                                  pending review (§4 row 4 / §5 test 6).
 */
final class HistoricalOverlapDedup
{
    /**
     * @param  iterable<HistoricalSalesDocument>  $documents
     * @return array{additive: Collection<int, HistoricalSalesDocument>, excludedIncludedInOpening: Collection<int, HistoricalSalesDocument>, excludedUnresolved: Collection<int, HistoricalSalesDocument>}
     */
    public static function partition(iterable $documents): array
    {
        $additive = new Collection;
        $excludedIncludedInOpening = new Collection;
        $excludedUnresolved = new Collection;

        foreach ($documents as $document) {
            if (! $document->opening_balance_overlap) {
                $additive->push($document);

                continue;
            }

            match ($document->opening_balance_resolution) {
                HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE => $additive->push($document),
                HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_INCLUDED => $excludedIncludedInOpening->push($document),
                default => $excludedUnresolved->push($document), // NULL — unresolved, never guessed
            };
        }

        return [
            'additive' => $additive,
            'excludedIncludedInOpening' => $excludedIncludedInOpening,
            'excludedUnresolved' => $excludedUnresolved,
        ];
    }
}
