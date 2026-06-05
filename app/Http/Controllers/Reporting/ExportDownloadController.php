<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Models\Reporting\ReportExport;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportAuditService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authorized download of a queued export file (frozen §20, resolves H-3).
 * The signed URL is NECESSARY BUT NOT SUFFICIENT: this endpoint also requires an
 * authenticated session, that the file belongs to the session tenant, and that
 * the user holds the originating report's view/export permission (plus
 * `reports.export_sensitive` when the file contains sensitive columns). A
 * leaked/forwarded/guessed link therefore cannot pull another tenant's — or
 * anyone's — export.
 *
 * Route applies the `signed` + `auth` middleware. Route-model binding resolves
 * ReportExport under the BelongsToShop global scope, so a foreign-tenant id
 * already 404s; the explicit checks below are defence in depth.
 */
class ExportDownloadController extends Controller
{
    public function __construct(
        private readonly ReportRegistry $registry,
    ) {
    }

    public function download(Request $request, ReportExport $export): StreamedResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        // Tenant binding (defence in depth over the global scope).
        abort_unless((int) $export->shop_id === (int) $user->shop_id, 403);

        // Report permission re-check at download time.
        abort_unless($this->registry->has($export->report_key), 404);
        $permissions = $this->registry->definition($export->report_key)->permissions;
        abort_unless($user->can($permissions->effectiveViewGate()) && $user->can($permissions->export), 403);
        if ($export->sensitive_included) {
            abort_unless($user->can($permissions->sensitive), 403);
        }

        // State + expiry + file presence.
        abort_unless($export->status === ExportAuditService::STATUS_DONE, 404);
        abort_if($export->expires_at !== null && $export->expires_at->isPast(), 410, 'This export link has expired. Please run the export again.');
        abort_if($export->file_disk === null || $export->file_path === null, 404);

        $disk = \Illuminate\Support\Facades\Storage::disk($export->file_disk);
        abort_unless($disk->exists($export->file_path), 410, 'This export file is no longer available. Please run the export again.');

        return $disk->download($export->file_path, basename($export->file_path));
    }
}
