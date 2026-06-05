<?php

namespace App\Services\Reporting\Render;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Wraps the installed headless Chromium to convert an HTML document to PDF
 * (frozen §4.1, §13). Chromium gives exact CSS fidelity — real tables,
 * page-break control, the ₹ glyph, web fonts — that PHP PDF libraries struggle
 * with. The app runs as root, hence `--no-sandbox`; `--disable-dev-shm-usage`
 * avoids the small /dev/shm crash in containers.
 *
 * The binary path comes from config('reporting.chromium_path'). When it is
 * missing or absent on disk, {@see convert()} throws a clear exception so callers
 * (and the smoke test) can detect/skip cleanly. A concurrency cap is noted by
 * the frozen architecture (§20) but is not enforced in Phase 0.
 *
 * Bound to {@see HtmlToPdf} so it stays injectable/mockable.
 */
final class ChromiumPdfService implements HtmlToPdf
{
    public function __construct(
        private readonly ?string $binaryPath,
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    /** Whether a usable Chromium binary is configured and present on disk. */
    public function isAvailable(): bool
    {
        return $this->binaryPath !== null
            && $this->binaryPath !== ''
            && is_file($this->binaryPath);
    }

    public function convert(string $html): string
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException(
                'Chromium binary not available for PDF rendering. '
                . 'Configure reporting.chromium_path (resolved: '
                . ($this->binaryPath ?? 'null') . ').'
            );
        }

        $workdir = $this->makeWorkDir();
        $htmlPath = $workdir . '/document.html';
        $pdfPath = $workdir . '/document.pdf';

        try {
            file_put_contents($htmlPath, $html);

            $process = new Process([
                $this->binaryPath,
                '--headless',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--no-pdf-header-footer',
                '--print-to-pdf-no-header',
                '--print-to-pdf=' . $pdfPath,
                'file://' . $htmlPath,
            ]);
            $process->setTimeout((float) $this->timeoutSeconds);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($pdfPath)) {
                throw new RuntimeException(
                    'Chromium failed to render PDF (exit '
                    . $process->getExitCode() . '): '
                    . trim($process->getErrorOutput() ?: $process->getOutput())
                );
            }

            $bytes = file_get_contents($pdfPath);

            if ($bytes === false || $bytes === '' || ! str_starts_with($bytes, '%PDF')) {
                throw new RuntimeException('Chromium produced an invalid PDF payload.');
            }

            return $bytes;
        } finally {
            $this->cleanup($workdir);
        }
    }

    private function makeWorkDir(): string
    {
        $base = sys_get_temp_dir() . '/jf-report-pdf-' . bin2hex(random_bytes(8));

        if (! @mkdir($base, 0700, true) && ! is_dir($base)) {
            throw new RuntimeException("Unable to create temp dir for PDF rendering: {$base}");
        }

        return $base;
    }

    private function cleanup(string $workdir): void
    {
        foreach (['document.html', 'document.pdf'] as $file) {
            $path = $workdir . '/' . $file;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($workdir);
    }
}
