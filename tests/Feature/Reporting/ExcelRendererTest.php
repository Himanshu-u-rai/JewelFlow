<?php

namespace Tests\Feature\Reporting;

use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Render\ExcelRenderer;
use Maatwebsite\Excel\Excel as ExcelWriter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use Tests\Unit\Reporting\Render\RendererFixtures;

/**
 * Analysis-ready XLSX (frozen §5.2): one sheet per section + a Report Info sheet,
 * frozen header + auto-filter, real typed cells, separated totals row, no banner
 * rows in the data region. The workbook is read back with PhpSpreadsheet.
 */
class ExcelRendererTest extends TestCase
{
    use RendererFixtures;

    private function renderToSpreadsheet(\App\Services\Reporting\Dataset\ReportDataset $dataset, \App\Services\Reporting\Dataset\ReportRequest $request)
    {
        $renderer = new ExcelRenderer(app(ExcelWriter::class));
        $out = $renderer->render($dataset, $request);

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $out->mimeType
        );
        $this->assertStringEndsWith('.xlsx', $out->filename);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsxtest') . '.xlsx';
        file_put_contents($tmp, $out->contents);
        $spreadsheet = IOFactory::load($tmp);
        @unlink($tmp);

        return $spreadsheet;
    }

    public function test_multi_section_one_sheet_per_section_plus_report_info(): void
    {
        $dataset = $this->multiSectionDataset();
        $spreadsheet = $this->renderToSpreadsheet($dataset, $this->fixtureRequest($dataset, F::Excel));

        $titles = $spreadsheet->getSheetNames();

        $this->assertContains('Report Info', $titles);
        $this->assertContains('B2B', $titles);
        $this->assertContains('B2CS', $titles);
        // Report Info + 2 sections.
        $this->assertCount(3, $titles);
    }

    public function test_data_sheet_is_clean_table_with_typed_cells(): void
    {
        $dataset = $this->singleSectionDataset();
        $spreadsheet = $this->renderToSpreadsheet($dataset, $this->fixtureRequest($dataset, F::Excel));

        $sheet = $spreadsheet->getSheetByName('Sales');
        $this->assertNotNull($sheet);

        // Row 1 is the header (no banner above it).
        $this->assertSame('Invoice No', $sheet->getCell('A1')->getValue());
        $this->assertSame('Amount', $sheet->getCell('E1')->getValue());

        // Money cell is a real number, not "₹..." text.
        $amount = $sheet->getCell('E2')->getValue();
        $this->assertIsNumeric($amount);
        $this->assertEqualsWithDelta(1234567.50, (float) $amount, 0.001);

        // Weight typed numeric.
        $weight = $sheet->getCell('C2')->getValue();
        $this->assertIsNumeric($weight);
        $this->assertEqualsWithDelta(12.345, (float) $weight, 0.0001);

        // Date cell is a real Excel date serial (numeric), formatted as a date.
        $dateCell = $sheet->getCell('B2');
        $this->assertIsNumeric($dateCell->getValue());
        $this->assertTrue(
            \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($dateCell),
            'Date column must be a real Excel date.'
        );
    }

    public function test_totals_row_is_separated_not_interleaved(): void
    {
        $dataset = $this->singleSectionDataset();
        $spreadsheet = $this->renderToSpreadsheet($dataset, $this->fixtureRequest($dataset, F::Excel));

        $sheet = $spreadsheet->getSheetByName('Sales');

        // Data is rows 2-3; row 4 is a blank gap; row 5 carries the total.
        $this->assertSame('Total', $sheet->getCell('A5')->getValue());
        $this->assertNull($sheet->getCell('A4')->getValue(), 'Blank gap row before totals.');

        $totalAmount = $sheet->getCell('E5')->getValue();
        $this->assertEqualsWithDelta(1290667.50, (float) $totalAmount, 0.001);
    }

    public function test_report_info_sheet_carries_provenance(): void
    {
        $dataset = $this->singleSectionDataset();
        $spreadsheet = $this->renderToSpreadsheet($dataset, $this->fixtureRequest($dataset, F::Excel));

        $info = $spreadsheet->getSheetByName('Report Info');
        $this->assertNotNull($info);

        $dump = [];
        foreach ($info->toArray() as $row) {
            foreach ($row as $cell) {
                if ($cell !== null && $cell !== '') {
                    $dump[] = (string) $cell;
                }
            }
        }
        $joined = implode('|', $dump);

        $this->assertStringContainsString('Shyam Jewellers Pvt Ltd', $joined);
        $this->assertStringContainsString('08ABCDE1234F1Z5', $joined);
        $this->assertStringContainsString('Ramesh Kumar', $joined);
    }
}
