<?php

namespace App\Services\Reporting\Definition;

/**
 * Output format (frozen §3.1, §5). Screen is the interactive view; PDF/Excel/CSV
 * are the export renderers. Each report declares which it allows.
 */
enum ExportFormat: string
{
    case Screen = 'screen';
    case Pdf = 'pdf';
    case Excel = 'excel';
    case Csv = 'csv';

    /** Chromium-rendered formal document (frozen §4). */
    public function isDocument(): bool
    {
        return $this === self::Pdf;
    }

    /** PDF has a lower queued-export threshold — render cost (frozen §20). */
    public function isExpensiveToRender(): bool
    {
        return $this === self::Pdf;
    }
}
