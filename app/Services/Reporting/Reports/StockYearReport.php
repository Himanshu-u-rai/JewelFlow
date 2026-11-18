<?php

namespace App\Services\Reporting\Reports;

use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ColumnDefinition;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Support\FinancialYear;

/**
 * What the three financial-year stock reports (closing stock, stock register by weight, trading account) share:
 * the year comes from the Period filter's end date (so "This FY", "Last FY" and a named year all mean the whole
 * year they fall in), and the document's filter echo says where the stock figures came from.
 */
trait StockYearReport
{
    protected function yearOf(ReportRequest $request): FinancialYear
    {
        $to = $request->filter('period', [])['to'] ?? null;

        return $to ? FinancialYear::forDate($to->toDateString()) : FinancialYear::current();
    }

    /**
     * The "Stock history" line for the filter echo, or [] when every date used is exact. Pass the 'YYYY-MM-DD' stock
     * dates the report rests on; one reconstructed date is enough to warn.
     *
     * @param  string[]  $dates
     * @return array<string, string>
     */
    protected function historyNote(int $shopId, array $dates): array
    {
        foreach ($dates as $d) {
            if (! \App\Services\StockAsAt::isExact($shopId, $d)) {
                return ['Stock history' => \App\Services\StockHistory::warning($shopId)];
            }
        }

        return [];
    }

    /** The same meta with extra lines in the "filters applied" echo printed on every format. */
    protected function withNotes(ReportMeta $meta, array $notes): ReportMeta
    {
        return new ReportMeta(
            reportKey: $meta->reportKey, reportVersion: $meta->reportVersion, title: $meta->title,
            profileLabel: $meta->profileLabel, format: $meta->format, filtersApplied: $meta->filtersApplied + $notes,
            periodLabel: $meta->periodLabel, shopLegalName: $meta->shopLegalName, shopAddress: $meta->shopAddress,
            shopGstin: $meta->shopGstin, shopStateCode: $meta->shopStateCode, generatedByName: $meta->generatedByName,
            generatedAt: $meta->generatedAt, generatorTag: $meta->generatorTag, watermark: $meta->watermark,
        );
    }

    /**
     * @param  string[]  $candidate
     * @param  string[]  $allowed
     * @return string[]
     */
    protected function keep(array $candidate, array $allowed): array
    {
        return array_values(array_filter($candidate, static fn ($k) => in_array($k, $allowed, true)));
    }

    /**
     * @param  string[]  $keys
     * @return ColumnDefinition[]
     */
    protected function cols(ReportDefinition $def, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $column = $def->column($key);
            if ($column !== null) {
                $out[] = $column;
            }
        }

        return $out;
    }

    /**
     * A row cut down to the columns of its section (a column the viewer may not see never leaves the dataset).
     *
     * @param  ColumnDefinition[]  $cols
     */
    protected function shape(array $row, array $cols): array
    {
        return array_intersect_key($row, array_flip(array_map(fn (ColumnDefinition $c) => $c->key, $cols)));
    }
}
