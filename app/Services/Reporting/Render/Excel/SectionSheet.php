<?php

namespace App\Services\Reporting\Render\Excel;

use App\Services\Reporting\Dataset\ReportSection;
use App\Services\Reporting\Definition\ColumnDefinition;
use App\Services\Reporting\Definition\ColumnType;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One report section as one clean Excel sheet (frozen §5.2): a single table with
 * a frozen header row + auto-filter, real typed cells (numbers as numbers, dates
 * as dates), and a clearly separated totals row below a blank gap — never
 * interleaved. NO banner rows in the data region.
 *
 * Cells are written directly against PhpSpreadsheet so we control the cell data
 * type and number format per column — `FromArray` alone would coerce everything
 * to strings/general.
 */
final class SectionSheet implements FromArray, WithTitle, WithEvents, ShouldAutoSize
{
    public function __construct(
        private readonly ReportSection $section,
        private readonly string $sheetTitle,
    ) {
    }

    /**
     * `FromArray` seeds the grid (header + raw scalars). The AfterSheet event
     * then re-types every data cell. Returning scalars here keeps the sheet
     * populated even if the event layer changes.
     *
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        $rows = [];

        $rows[] = array_map(
            static fn (ColumnDefinition $c): string => $c->label,
            $this->section->columns
        );

        foreach ($this->section->rows as $row) {
            $line = [];
            foreach ($this->section->columns as $column) {
                $line[] = $this->seedValue($row[$column->key] ?? null, $column->type);
            }
            $rows[] = $line;
        }

        return $rows;
    }

    public function title(): string
    {
        return $this->sheetTitle;
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $columns = $this->section->columns;
                $colCount = count($columns);

                if ($colCount === 0) {
                    return;
                }

                $lastCol = $this->columnLetter($colCount);
                $dataLastRow = 1 + $this->section->rowCount();

                $this->styleHeader($sheet, $lastCol);
                $this->typeDataCells($sheet, $columns);
                $this->freezeAndFilter($sheet, $lastCol, $dataLastRow);
                $this->writeTotals($sheet, $columns, $dataLastRow);
            },
        ];
    }

    private function styleHeader(Worksheet $sheet, string $lastCol): void
    {
        $headerRange = 'A1:' . $lastCol . '1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF0FDFA');
    }

    /**
     * @param ColumnDefinition[] $columns
     */
    private function typeDataCells(Worksheet $sheet, array $columns): void
    {
        foreach ($this->section->rows as $rowIndex => $row) {
            $excelRow = $rowIndex + 2; // header is row 1
            foreach ($columns as $colIndex => $column) {
                $letter = $this->columnLetter($colIndex + 1);
                $coord = $letter . $excelRow;
                $this->writeTypedCell($sheet, $coord, $row[$column->key] ?? null, $column->type);
            }
        }
    }

    private function freezeAndFilter(Worksheet $sheet, string $lastCol, int $dataLastRow): void
    {
        $sheet->freezePane('A2');
        $filterLastRow = max(1, $dataLastRow);
        $sheet->setAutoFilter('A1:' . $lastCol . $filterLastRow);
    }

    /**
     * Totals on a separate row, after one blank gap row — never interleaved.
     *
     * @param ColumnDefinition[] $columns
     */
    private function writeTotals(Worksheet $sheet, array $columns, int $dataLastRow): void
    {
        if (! $this->section->hasTotals()) {
            return;
        }

        $totalRow = $dataLastRow + 2; // blank gap row in between
        $first = true;
        foreach ($columns as $colIndex => $column) {
            $letter = $this->columnLetter($colIndex + 1);
            $coord = $letter . $totalRow;
            if ($first) {
                $sheet->setCellValueExplicit($coord, 'Total', DataType::TYPE_STRING);
                $sheet->getStyle($coord)->getFont()->setBold(true);
                $first = false;
                if (! array_key_exists($column->key, $this->section->totals)) {
                    continue;
                }
            }
            if (array_key_exists($column->key, $this->section->totals)) {
                $this->writeTypedCell($sheet, $coord, $this->section->totals[$column->key], $column->type);
                $sheet->getStyle($coord)->getFont()->setBold(true);
            }
        }
    }

    /** Seed scalar used by FromArray before the event re-types cells. */
    private function seedValue(mixed $value, ColumnType $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            ColumnType::Integer => (int) $this->num($value),
            ColumnType::Decimal, ColumnType::Money, ColumnType::Weight, ColumnType::Percent => $this->num($value),
            ColumnType::Boolean => $this->bool($value),
            default => $this->scalar($value),
        };
    }

    private function writeTypedCell(Worksheet $sheet, string $coord, mixed $value, ColumnType $type): void
    {
        if ($value === null || $value === '') {
            $sheet->setCellValueExplicit($coord, null, DataType::TYPE_NULL);
            return;
        }

        switch ($type) {
            case ColumnType::Integer:
                $sheet->setCellValueExplicit($coord, (string) (int) $this->num($value), DataType::TYPE_NUMERIC);
                $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('#,##0');
                break;

            case ColumnType::Money:
                $sheet->setCellValueExplicit($coord, (string) $this->num($value), DataType::TYPE_NUMERIC);
                $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('[$₹-en-IN]#,##0.00');
                break;

            case ColumnType::Decimal:
                $sheet->setCellValueExplicit($coord, (string) $this->num($value), DataType::TYPE_NUMERIC);
                $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('#,##0.00');
                break;

            case ColumnType::Weight:
                $sheet->setCellValueExplicit($coord, (string) $this->num($value), DataType::TYPE_NUMERIC);
                $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('#,##0.000');
                break;

            case ColumnType::Percent:
                // Stored as the literal percentage number (e.g. 12.5), shown with a % suffix
                // but NOT divided by 100 (the dataset already carries the percent value).
                $sheet->setCellValueExplicit($coord, (string) $this->num($value), DataType::TYPE_NUMERIC);
                $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('#,##0.00"%"');
                break;

            case ColumnType::Date:
                $carbon = $this->toDate($value);
                if ($carbon !== null) {
                    $sheet->setCellValue($coord, ExcelDate::PHPToExcel($carbon->copy()->startOfDay()));
                    $sheet->getStyle($coord)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
                } else {
                    $sheet->setCellValueExplicit($coord, $this->scalar($value), DataType::TYPE_STRING);
                }
                break;

            case ColumnType::DateTime:
                $carbon = $this->toDate($value);
                if ($carbon !== null) {
                    $sheet->setCellValue($coord, ExcelDate::PHPToExcel($carbon));
                    $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('dd-mm-yyyy hh:mm');
                } else {
                    $sheet->setCellValueExplicit($coord, $this->scalar($value), DataType::TYPE_STRING);
                }
                break;

            case ColumnType::Boolean:
                $sheet->setCellValueExplicit($coord, $this->bool($value) ? 'TRUE' : 'FALSE', DataType::TYPE_BOOL);
                break;

            default:
                $sheet->setCellValueExplicit($coord, $this->scalar($value), DataType::TYPE_STRING);
        }
    }

    private function columnLetter(int $oneBased): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($oneBased);
    }

    private function num(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 't'], true);
        }

        return (bool) $value;
    }

    private function toDate(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return \Carbon\Carbon::instance($value);
        }
        if (is_string($value) && $value !== '') {
            try {
                return \Carbon\Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function scalar(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_array($value)) {
            return implode('; ', array_map([$this, 'scalar'], $value));
        }

        return (string) $value;
    }
}
