<?php

namespace App\Providers;

use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportSizeRouter;
use App\Services\Reporting\Render\ChromiumPdfService;
use App\Services\Reporting\Render\HtmlToPdf;
use App\Services\Reporting\Reports\SalesRegisterDataset;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the reporting-export spine (Phase 0). The registry is a singleton that
 * reports self-register into (per-report bindings arrive with each report in
 * Phase 1+). The Chromium engine is bound behind the HtmlToPdf interface so the
 * PDF renderer stays testable/mockable. Every other service auto-resolves from
 * the container.
 */
class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportRegistry::class, fn (Container $app) => new ReportRegistry($app));

        $this->app->bind(HtmlToPdf::class, fn () => new ChromiumPdfService(
            config('reporting.chromium_path'),
            (int) config('reporting.chromium_timeout_seconds', 60),
        ));

        $this->app->singleton(ExportSizeRouter::class, fn () => ExportSizeRouter::fromConfig());
    }

    public function boot(): void
    {
        /** @var ReportRegistry $registry */
        $registry = $this->app->make(ReportRegistry::class);

        // Phase 1 pilot: the canonical Sales / Invoice Register (Addendum C §27).
        if (! $registry->has(SalesRegisterDataset::KEY)) {
            $registry->register(SalesRegisterDataset::KEY, SalesRegisterDataset::class);
        }
    }
}
