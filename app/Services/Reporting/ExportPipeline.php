<?php

namespace App\Services\Reporting;

use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\Render\RendererFactory;

/**
 * The single build-then-render path, shared by the synchronous controller and
 * the queued job so both produce identical output (frozen §3.1). Resolves the
 * report's dataset service from the registry, builds the canonical RowSet with
 * the pre-computed meta, and renders it to the requested file format. Renderers
 * consume the dataset only — never re-query.
 */
class ExportPipeline
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly RendererFactory $renderers,
    ) {
    }

    public function run(ReportRequest $request, ReportMeta $meta): ExportResult
    {
        $dataset = $this->registry
            ->datasetService($request->definition->key)
            ->build($request, $meta);

        $output = $this->renderers->for($request->format)->render($dataset, $request);

        return new ExportResult($output, $dataset->totalRowCount());
    }
}
