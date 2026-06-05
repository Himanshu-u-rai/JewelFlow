<?php

namespace App\Services\Reporting\Render;

/**
 * HTML → PDF conversion contract (frozen §4.1). Kept as an interface so the PDF
 * renderer is injectable/mockable: tests can bind a fake that returns a stub
 * "%PDF" payload without invoking a real browser. The concrete implementation
 * is {@see ChromiumPdfService}.
 */
interface HtmlToPdf
{
    /**
     * Convert a full HTML document to PDF bytes.
     *
     * @throws \RuntimeException if the renderer binary is unavailable or fails.
     */
    public function convert(string $html): string;
}
