<?php

namespace App\Services\Reporting\Render\Excel;

use App\Services\Reporting\Dataset\ReportDataset;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The maatwebsite export object assembled by {@see \App\Services\Reporting\Render\ExcelRenderer}.
 * One {@see SectionSheet} per report section (multi-section reports like GSTR-1
 * become multiple sheets) plus a leading {@see ReportInfoSheet} carrying all
 * provenance (frozen §5.2).
 */
final class ReportWorkbook implements WithMultipleSheets
{
    public function __construct(private readonly ReportDataset $dataset)
    {
    }

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        $sheets = [new ReportInfoSheet($this->dataset->meta)];

        $usedTitles = [];
        foreach ($this->dataset->sections as $index => $section) {
            $title = $this->uniqueSheetTitle($section->title !== '' ? $section->title : ($section->key !== '' ? $section->key : 'Section ' . ($index + 1)), $usedTitles);
            $sheets[] = new SectionSheet($section, $title);
        }

        return $sheets;
    }

    /**
     * Excel sheet titles are capped at 31 chars and must be unique and free of
     * the characters []:*?/\.
     *
     * @param array<string,bool> $used
     */
    private function uniqueSheetTitle(string $raw, array &$used): string
    {
        $clean = preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', $raw) ?? $raw;
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
        if ($clean === '') {
            $clean = 'Section';
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
