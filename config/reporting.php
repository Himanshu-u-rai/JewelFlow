<?php

/**
 * Reporting-export spine configuration (frozen §4, §20).
 *
 * Phase 0 only needs the Chromium binary path (for PDF rendering) and the
 * export-size thresholds that later decide sync-vs-queued routing. Wiring of
 * the services that consume these lives in a later phase.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Headless Chromium binary
    |--------------------------------------------------------------------------
    | Path to the headless Chromium/Chrome binary used to render the shared
    | report-document HTML print template to PDF (frozen §4.1). When the env
    | override is absent we glob the Playwright cache for the bundled binary;
    | if nothing is found this resolves to null and PdfRenderer throws a clear
    | exception (tests skip).
    */
    'chromium_path' => env('REPORTING_CHROMIUM_PATH') ?: (static function (): ?string {
        $matches = glob('/root/.cache/ms-playwright/chromium-*/chrome-linux64/chrome');
        if ($matches === false || $matches === []) {
            return null;
        }
        // Prefer the highest build number (last when sorted).
        sort($matches);
        return end($matches) ?: null;
    })(),

    /*
    |--------------------------------------------------------------------------
    | Chromium render controls
    |--------------------------------------------------------------------------
    | Per-render timeout (seconds) and a concurrency cap noted by the frozen
    | architecture (§20). Phase 0 does not enforce the cap, but the value is
    | declared here so the later queued path reads a single source of truth.
    */
    'chromium_timeout_seconds' => (int) env('REPORTING_CHROMIUM_TIMEOUT', 60),
    'chromium_max_concurrency' => (int) env('REPORTING_CHROMIUM_MAX_CONCURRENCY', 2),

    /*
    |--------------------------------------------------------------------------
    | Export-size thresholds (frozen §20)
    |--------------------------------------------------------------------------
    | Placeholders for the size router added in a later phase. PDF is the most
    | expensive renderer and therefore carries a lower row ceiling before an
    | export is pushed onto the queue.
    */
    'size_thresholds' => [
        'sync_max_rows'     => (int) env('REPORTING_SYNC_MAX_ROWS', 5000),
        'pdf_sync_max_rows' => (int) env('REPORTING_PDF_SYNC_MAX_ROWS', 1500),
    ],

    /*
    |--------------------------------------------------------------------------
    | "Export everything" backup ceiling
    |--------------------------------------------------------------------------
    | The combined Excel backup builds every data set synchronously in one
    | request, so it bypasses the size router above. If the total estimated rows
    | across all data sets exceed this ceiling, the backup is refused and the
    | owner is directed to export individual data sets (which auto-queue).
    */
    'backup_max_rows' => (int) env('REPORTING_BACKUP_MAX_ROWS', 50000),

];
