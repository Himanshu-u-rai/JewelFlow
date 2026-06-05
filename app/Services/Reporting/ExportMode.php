<?php

namespace App\Services\Reporting;

/**
 * How an export is produced (frozen §20). Small exports run inline; large ones
 * are queued, generated to transient storage with a signed expiring link.
 */
enum ExportMode: string
{
    case Sync = 'sync';
    case Queued = 'queued';
}
