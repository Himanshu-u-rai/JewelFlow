<?php

namespace App\Console\Commands;

use App\Services\FraudDetectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class DetectFraud extends Command
{
    protected $signature   = 'platform:detect-fraud';
    protected $description = 'Run fraud detection checks across all tenants and flag suspicious activity';

    public function handle(FraudDetectionService $service): int
    {
        $flagged = $service->runAllChecks();
        $count   = count($flagged);

        $this->info("Fraud detection complete. {$count} new flag(s) raised.");

        if ($count > 0) {
            $alertEmail = config('app.platform_alert_email', env('PLATFORM_ALERT_EMAIL'));
            if ($alertEmail) {
                $shopList = implode(', ', array_unique($flagged));
                Mail::raw(
                    "JewelFlow Fraud Detection Alert\n\n" .
                    "{$count} new fraud flag(s) were raised during the scheduled scan.\n\n" .
                    "Affected shop IDs: {$shopList}\n\n" .
                    "Please review the flags at /admin/fraud-flags\n\n" .
                    "Time: " . now()->toDateTimeString(),
                    function ($message) use ($alertEmail, $count) {
                        $message->to($alertEmail)
                                ->subject("[JewelFlow] {$count} Fraud Flag(s) Detected — Action Required");
                    }
                );
                $this->info("Alert email sent to {$alertEmail}.");
            } else {
                $this->warn('PLATFORM_ALERT_EMAIL is not configured; skipping alert email.');
            }
        }

        return Command::SUCCESS;
    }
}
