<?php

namespace App\Services\Dhiran;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a Dhiran CSV export: UTF-8 with a BOM (Excel shows ₹ and Indian names right), rows written as they are
 * read so tens of thousands of loans never sit in memory, and no cell can run as a spreadsheet formula.
 */
final class DhiranCsv
{
    /** @param iterable<array<int, mixed>> $rows */
    public static function download(string $name, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'cell'], $row));
            }
            fclose($out);
        }, 'dhiran-' . $name . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Text starting with = + - @ (or a tab/CR) would run as a formula in Excel: prefix it with '. Numbers pass. */
    public static function cell(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        $s = is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v;

        return ! is_numeric($s) && preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
    }

    /** Plain number, two decimals, no grouping: Excel can add it up. */
    public static function money(mixed $v): ?string
    {
        return $v === null ? null : number_format((float) $v, 2, '.', '');
    }

    /** ISO date (sorts and parses everywhere). */
    public static function date(mixed $d): ?string
    {
        return $d ? \Illuminate\Support\Carbon::parse($d)->toDateString() : null;
    }

    public static function dateTime(mixed $d): ?string
    {
        return $d ? \Illuminate\Support\Carbon::parse($d)->format('Y-m-d H:i') : null;
    }
}
