<?php

namespace App\Services\Reporting\Dataset;

use App\Services\Reporting\Definition\ReportDefinition;

/**
 * The contract every report implements (frozen §3.1). One concrete subclass per
 * report. `definition()` is the declarative metadata (cheap, no queries — used
 * by the registry, export panel, and permission checks). `build()` is the
 * single authoritative query path producing the canonical RowSet; renderers
 * consume its output and never query themselves (frozen §3.1, §13).
 *
 * Implementations MUST:
 *  - eager-load per-row relations and stay O(1) in queries (invariant #8 / M-2);
 *  - return ONLY the columns in `$request->columnKeys` (the gate already ran);
 *  - emit native typed values, never formatted strings (§3.1).
 */
abstract class ReportDatasetService
{
    abstract public function definition(): ReportDefinition;

    abstract public function build(ReportRequest $request): ReportDataset;
}
