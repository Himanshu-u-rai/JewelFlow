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

    /**
     * Build the canonical RowSet. The cross-cutting `ReportMeta` (provenance,
     * watermark, period label, shop furniture) is computed by the pipeline and
     * passed in, so the report only produces sections/rows/totals and attaches
     * the given meta verbatim.
     */
    abstract public function build(ReportRequest $request, ReportMeta $meta): ReportDataset;

    /**
     * Optional row-count estimate used by the size router to decide sync vs
     * queued (frozen §20). Return null when unknown — the router then treats it
     * as small (sync). Reports SHOULD override with a cheap COUNT for accuracy.
     */
    public function estimateRowCount(ReportRequest $request): ?int
    {
        return null;
    }
}
