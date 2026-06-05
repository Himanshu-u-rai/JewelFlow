<?php

namespace App\Services\Reporting\Render;

use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Dataset\ReportSection;
use App\Services\Reporting\Definition\ColumnDefinition;
use App\Services\Reporting\Definition\ColumnType;
use App\Services\Reporting\Definition\ExportFormat;
use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Clean machine-CSV writer (frozen §5.3). Exactly one header row followed by
 * raw-value rows — NO banner rows, NO blank/label rows, NO metadata inside the
 * CSV. This is the explicit fix for today's banner-CSV.
 *
 * Raw value rules: ISO YYYY-MM-DD dates (and ISO 8601 for datetimes), decimals
 * with '.', no ₹, no thousands separators, booleans as true/false. These bypass
 * {@see ValueFormatter} deliberately.
 *
 * Single-section → one CSV. Multi-section → a ZIP of per-section CSVs plus a
 * `<report>-meta.json` sidecar carrying the ReportMeta provenance.
 *
 * Consumes the dataset ONLY (frozen §3.1, §13).
 */
final class CsvRenderer implements ReportRenderer
{
    public function supports(ExportFormat $format): bool
    {
        return $format === ExportFormat::Csv;
    }

    public function render(ReportDataset $dataset, ReportRequest $request): RenderedOutput
    {
        if (! $this->supports($request->format)) {
            throw new InvalidArgumentException(
                "CsvRenderer cannot render format [{$request->format->value}]."
            );
        }

        $base = $this->slug($dataset->meta->reportKey) . '-' . $dataset->meta->generatedAt->format('Ymd-His');

        if ($dataset->isMultiSection()) {
            return $this->renderZip($dataset, $base);
        }

        $section = $dataset->sections[0] ?? new ReportSection('empty', '', [], []);

        return new RenderedOutput(
            filename: $base . '.csv',
            mimeType: 'text/csv',
            contents: $this->sectionToCsv($section),
        );
    }

    private function renderZip(ReportDataset $dataset, string $base): RenderedOutput
    {
        $tmp = tempnam(sys_get_temp_dir(), 'jf-report-csv-');
        if ($tmp === false) {
            throw new RuntimeException('Unable to allocate temp file for CSV ZIP.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Unable to open ZIP archive for multi-section CSV export.');
        }

        $usedNames = [];
        foreach ($dataset->sections as $index => $section) {
            $name = $this->uniqueEntryName($section, $index, $usedNames);
            $zip->addFromString($name, $this->sectionToCsv($section));
        }

        $zip->addFromString(
            $base . '-meta.json',
            $this->metaJson($dataset->meta)
        );

        $zip->close();

        $contents = file_get_contents($tmp);
        @unlink($tmp);

        if ($contents === false) {
            throw new RuntimeException('Failed to read generated CSV ZIP archive.');
        }

        return new RenderedOutput(
            filename: $base . '.zip',
            mimeType: 'application/zip',
            contents: $contents,
        );
    }

    /** Build a single clean CSV table for one section. */
    private function sectionToCsv(ReportSection $section): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open in-memory CSV buffer.');
        }

        $columns = $section->columns;

        // Single header row.
        fputcsv($handle, array_map(static fn (ColumnDefinition $c): string => $c->label, $columns));

        // Raw-value rows — nothing else.
        foreach ($section->rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = $this->raw($row[$column->key] ?? null, $column->type);
            }
            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /** Raw, machine-friendly scalar string for a typed value. */
    private function raw(mixed $value, ColumnType $type): string
    {
        if ($value === null) {
            return '';
        }

        return match ($type) {
            ColumnType::Boolean => $this->bool($value) ? 'true' : 'false',
            ColumnType::Date => $this->toDate($value)?->format('Y-m-d') ?? $this->scalar($value),
            ColumnType::DateTime => $this->toDate($value)?->format('Y-m-d\TH:i:s') ?? $this->scalar($value),
            ColumnType::Integer => (string) (int) $this->num($value),
            ColumnType::Decimal, ColumnType::Money => $this->decimal($value, 2),
            ColumnType::Weight => $this->decimal($value, 3),
            ColumnType::Percent => $this->trimZeros($this->num($value)),
            default => $this->scalar($value),
        };
    }

    private function decimal(mixed $value, int $places): string
    {
        return number_format($this->num($value), $places, '.', '');
    }

    private function trimZeros(float $value): string
    {
        $s = rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
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
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return implode('; ', array_map([$this, 'scalar'], $value));
        }

        return (string) $value;
    }

    /**
     * @param array<string,bool> $used
     */
    private function uniqueEntryName(ReportSection $section, int $index, array &$used): string
    {
        $stem = $this->slug($section->key !== '' ? $section->key : 'section-' . ($index + 1));
        if ($stem === '') {
            $stem = 'section-' . ($index + 1);
        }

        $name = $stem . '.csv';
        $suffix = 2;
        while (isset($used[$name])) {
            $name = $stem . '-' . $suffix . '.csv';
            $suffix++;
        }
        $used[$name] = true;

        return $name;
    }

    private function metaJson(ReportMeta $meta): string
    {
        $payload = [
            'reportKey'       => $meta->reportKey,
            'reportVersion'   => $meta->reportVersion,
            'title'           => $meta->title,
            'profileLabel'    => $meta->profileLabel,
            'format'          => $meta->format,
            'filtersApplied'  => $meta->filtersApplied,
            'periodLabel'     => $meta->periodLabel,
            'shopLegalName'   => $meta->shopLegalName,
            'shopAddress'     => $meta->shopAddress,
            'shopGstin'       => $meta->shopGstin,
            'shopStateCode'   => $meta->shopStateCode,
            'generatedByName' => $meta->generatedByName,
            'generatedAt'     => $meta->generatedAt->toIso8601String(),
            'generatorTag'    => $meta->generatorTag,
            'watermark'       => $meta->watermark,
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ?: '{}';
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)) ?? $value;

        return trim($slug, '-');
    }
}
