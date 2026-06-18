<?php

namespace App\Services\Reporting\Render\Excel;

use App\Services\Reporting\Dataset\ReportSection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The multi-dataset "Export everything" workbook: one {@see SectionSheet} per
 * data set (Customers, Products, Inventory Items, …), reusing the same sheet
 * renderer the per-report Excel export uses. Built by
 * {@see \App\Http\Controllers\ExportController::exportAllWorkbook}.
 *
 * @param array<int, array{title:string, section:ReportSection}> $datasets
 */
final class BackupWorkbook implements WithMultipleSheets
{
    /**
     * @param array<int, array{title:string, section:ReportSection}> $datasets
     */
    public function __construct(private readonly array $datasets)
    {
    }

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        $sheets = [];
        $used = [];

        foreach ($this->datasets as $entry) {
            $title = $this->uniqueSheetTitle($entry['title'], $used);
            $sheets[] = new SectionSheet($entry['section'], $title);
        }

        return $sheets;
    }

    /**
     * Excel sheet titles: ≤31 chars, unique, free of []:*?/\ (mirrors ReportWorkbook).
     *
     * @param array<string,bool> $used
     */
    private function uniqueSheetTitle(string $raw, array &$used): string
    {
        $clean = preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', $raw) ?? $raw;
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
        if ($clean === '') {
            $clean = 'Sheet';
        }
        $clean = mb_substr($clean, 0, 31);

        $title = $clean;
        $suffix = 2;
        while (isset($used[mb_strtolower($title)])) {
            $tag = ' (' . $suffix . ')';
            $title = mb_substr($clean, 0, 31 - mb_strlen($tag)) . $tag;
            $suffix++;
        }
        $used[mb_strtolower($title)] = true;

        return $title;
    }
}
