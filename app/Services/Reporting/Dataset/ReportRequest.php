<?php

namespace App\Services\Reporting\Dataset;

use App\Services\Reporting\Definition\ColumnDefinition;
use App\Services\Reporting\Definition\ExportFormat;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportProfile;

/**
 * A fully resolved export/view request handed to a dataset service (frozen §3.1, §6).
 *
 * Filters are already resolved to explicit values (FY presets → stamped dates,
 * frozen §17). `columnKeys` is the FINAL column set after profile defaults +
 * user toggles + the sensitive gate (ColumnPolicy, §7) — the dataset only
 * populates these. `includeSensitive` / `revealMasked` are recorded for the
 * export-audit row (§16); they are pre-authorized here (the gate is enforced
 * before the request is built, never inside the renderer).
 */
final class ReportRequest
{
    /**
     * @param array<string, mixed> $filters resolved filter values
     * @param string[]             $columnKeys final, ordered, gated column keys
     */
    public function __construct(
        public readonly ReportDefinition $definition,
        public readonly int $shopId,
        public readonly ?int $userId,
        public readonly string $userName,
        public readonly ReportProfile $profile,
        public readonly ExportFormat $format,
        public readonly array $filters,
        public readonly array $columnKeys,
        public readonly bool $includeSensitive = false,
        public readonly bool $revealMasked = false,
    ) {
    }

    public function hasColumn(string $key): bool
    {
        return in_array($key, $this->columnKeys, true);
    }

    public function filter(string $key, mixed $default = null): mixed
    {
        return $this->filters[$key] ?? $default;
    }

    /**
     * Resolved column definitions in request order (intersection of the
     * definition catalogue and the gated selection).
     *
     * @return ColumnDefinition[]
     */
    public function columns(): array
    {
        $resolved = [];
        foreach ($this->columnKeys as $key) {
            $column = $this->definition->column($key);
            if ($column !== null) {
                $resolved[] = $column;
            }
        }
        return $resolved;
    }
}
