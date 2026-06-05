<?php

namespace App\Notifications\Reporting;

use App\Models\Reporting\ReportExport;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Tells the requester a queued export is ready, with a signed, expiring link to
 * the authorized download endpoint (frozen §20). In-app by default; email is an
 * optional channel a shop can enable later.
 */
class ExportReadyNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly ReportExport $export)
    {
    }

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'reporting_export_ready',
            'export_id' => $this->export->id,
            'report_key' => $this->export->report_key,
            'download_url' => $this->downloadUrl(),
            'expires_at' => optional($this->export->expires_at)->toIso8601String(),
            'message' => 'Your export is ready to download.',
        ];
    }

    private function downloadUrl(): string
    {
        $expiry = $this->export->expires_at ?? now()->addDays((int) config('reporting.download_expiry_days', 7));

        return URL::temporarySignedRoute('reporting.exports.download', $expiry, ['export' => $this->export->id]);
    }
}
