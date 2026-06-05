<?php

namespace App\Services\Reporting\Dataset;

use App\Services\Reporting\Definition\ColumnDefinition;

/**
 * One table within a dataset (frozen §3.1). Single-table reports have exactly
 * one section; multi-part reports (e.g. GSTR-1 B2B / B2CS / HSN, or a Dhiran
 * loan statement's summary/payments/pledged-items) have several, each with its
 * own columns/rows/totals. Rows carry NATIVE typed values keyed by column key;
 * renderers format per ColumnType — the dataset never holds formatted strings
 * (frozen §3.1, §5).
 *
 * @phpstan-type Row array<string, mixed>
 */
final class ReportSection
{
    /**
     * @param ColumnDefinition[]            $columns ordered, resolved
     * @param array<int, array<string,mixed>> $rows
     * @param array<string, mixed>          $totals  column key => typed total/subtotal
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $totals = [],
    ) {
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function hasTotals(): bool
    {
        return $this->totals !== [];
    }

    /** @return string[] resolved column keys, in order. */
    public function columnKeys(): array
    {
        return array_map(static fn (ColumnDefinition $c) => $c->key, $this->columns);
    }
}
