<?php

namespace App\Reporting\Export;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reusable streamed CSV exporter for reporting (Phase 2 M0).
 *
 * Promotes the proven chunked-stream pattern out of ExportController so every
 * CA/operational report exports identically (UTF-8 BOM for Excel, chunked to
 * avoid loading everything into memory). Screen and export read the same
 * Reporting service/DTO — this class only renders.
 */
class CsvReportExporter
{
    /** Stream a CSV from an in-memory array of rows (each row = array of cells). */
    public static function fromRows(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Stream a CSV from an Eloquent builder, chunked, mapping each record to a row. */
    public static function fromQuery(string $filename, array $headers, Builder $query, callable $rowMapper, int $chunkSize = 500): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $query, $rowMapper, $chunkSize): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            $query->chunk($chunkSize, function ($records) use ($out, $rowMapper) {
                foreach ($records as $record) {
                    fputcsv($out, $rowMapper($record));
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
