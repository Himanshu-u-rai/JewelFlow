<?php

namespace Tests\Unit\Reporting;

use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\ExportMode;
use App\Services\Reporting\ExportSizeRouter;
use PHPUnit\Framework\TestCase;

/**
 * Sync-vs-queued routing by row count, with PDF on a lower threshold (frozen §20).
 */
class ExportSizeRouterTest extends TestCase
{
    private ExportSizeRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new ExportSizeRouter(syncMaxRows: 5000, pdfSyncMaxRows: 1500);
    }

    public function test_csv_uses_high_threshold(): void
    {
        $this->assertSame(ExportMode::Sync, $this->router->mode(F::Csv, 5000));
        $this->assertSame(ExportMode::Queued, $this->router->mode(F::Csv, 5001));
    }

    public function test_pdf_uses_lower_threshold(): void
    {
        $this->assertSame(ExportMode::Sync, $this->router->mode(F::Pdf, 1500));
        $this->assertSame(ExportMode::Queued, $this->router->mode(F::Pdf, 1501));
    }

    public function test_pdf_queues_where_csv_would_not(): void
    {
        $this->assertFalse($this->router->shouldQueue(F::Csv, 3000));
        $this->assertTrue($this->router->shouldQueue(F::Pdf, 3000));
    }

    public function test_threshold_for(): void
    {
        $this->assertSame(1500, $this->router->thresholdFor(F::Pdf));
        $this->assertSame(5000, $this->router->thresholdFor(F::Excel));
    }
}
