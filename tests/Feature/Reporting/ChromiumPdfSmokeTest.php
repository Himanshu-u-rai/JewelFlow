<?php

namespace Tests\Feature\Reporting;

use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Render\ChromiumPdfService;
use App\Services\Reporting\Render\PdfRenderer;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Tests\TestCase;
use Tests\Unit\Reporting\Render\RendererFixtures;

/**
 * Renders the shared report-document layout to a real PDF via headless Chromium
 * (frozen §4.1). Skips gracefully where the Chromium binary is absent (CI), so
 * the spine still builds without a browser present.
 */
class ChromiumPdfSmokeTest extends TestCase
{
    use RendererFixtures;

    private function chromiumOrSkip(): ChromiumPdfService
    {
        $path = config('reporting.chromium_path');

        if (! is_string($path) || $path === '' || ! file_exists($path)) {
            $this->markTestSkipped('Chromium binary not available at: ' . var_export($path, true));
        }

        return new ChromiumPdfService($path, (int) config('reporting.chromium_timeout_seconds', 60));
    }

    public function test_renders_report_document_to_valid_pdf(): void
    {
        $chromium = $this->chromiumOrSkip();

        $dataset = $this->singleSectionDataset();
        $renderer = new PdfRenderer($chromium, app(ViewFactory::class));

        $out = $renderer->render($dataset, $this->fixtureRequest($dataset, F::Pdf));

        $this->assertSame('application/pdf', $out->mimeType);
        $this->assertStringEndsWith('.pdf', $out->filename);
        $this->assertStringStartsWith('%PDF', $out->contents, 'Output must be a valid PDF.');
        $this->assertGreaterThan(1000, $out->byteSize(), 'A real rendered PDF should be non-trivial in size.');
    }

    public function test_watermarked_document_also_renders(): void
    {
        $chromium = $this->chromiumOrSkip();

        $dataset = $this->singleSectionDataset(watermark: 'DRAFT');
        $renderer = new PdfRenderer($chromium, app(ViewFactory::class));

        $out = $renderer->render($dataset, $this->fixtureRequest($dataset, F::Pdf));

        $this->assertStringStartsWith('%PDF', $out->contents);
    }
}
