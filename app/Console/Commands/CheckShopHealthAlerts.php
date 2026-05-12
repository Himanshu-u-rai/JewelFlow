<?php

namespace App\Console\Commands;

use App\Models\Platform\PlatformAuditLog;
use App\Models\Shop;
use App\Services\PlatformAuditService;
use App\Services\TenantHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckShopHealthAlerts extends Command
{
    protected $signature   = 'platform:check-shop-health';
    protected $description = 'Check tenant health scores and log/alert for shops below the critical threshold';

    public function handle(TenantHealthService $healthService, PlatformAuditService $audit): int
    {
        $this->info('Running shop health check…');

        $scores = $healthService->scores();

        $critical = $scores->filter(fn (array $entry) => $entry['score'] < 30);

        if ($critical->isEmpty()) {
            $this->info('All shops are above the critical threshold. Nothing to alert.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$critical->count()} shop(s) with health score < 30.");

        $digestLines = [];

        foreach ($critical as $entry) {
            /** @var Shop $shop */
            $shop  = $entry['shop'];
            $score = $entry['score'];
            $notes = $entry['notes'] ?? [];

            // Deduplicate: skip if an unresolved alert was already logged in the last 24 hours.
            $recentAlert = PlatformAuditLog::query()
                ->where('action', 'shop.health_alert_low')
                ->where('target_type', Shop::class)
                ->where('target_id', $shop->id)
                ->where('created_at', '>=', now()->subHours(24))
                ->exists();

            if ($recentAlert) {
                $this->line("  Skipping shop #{$shop->id} ({$shop->name}) — alert already logged within 24h.");
                continue;
            }

            try {
                $audit->log(
                    null,
                    'shop.health_alert_low',
                    Shop::class,
                    $shop->id,
                    null,
                    [
                        'score'               => $score,
                        'band'                => $entry['band'] ?? 'critical',
                        'notes'               => $notes,
                        'subscription_status' => $entry['subscription_status'] ?? 'unknown',
                        'invoice_count'       => $entry['invoice_count'] ?? 0,
                        'failed_imports'      => $entry['failed_imports'] ?? 0,
                    ]
                );

                $this->line("  Logged alert for shop #{$shop->id} ({$shop->name}) — score {$score}.");
            } catch (\Throwable $e) {
                Log::error("CheckShopHealthAlerts: failed to log audit entry for shop #{$shop->id}: " . $e->getMessage());
                $this->error("  Failed to log audit entry for shop #{$shop->id}: " . $e->getMessage());
            }

            $notesStr = $notes ? implode('; ', $notes) : 'none';
            $digestLines[] = "  • [{$score}/100] {$shop->name} (ID #{$shop->id}) — {$notesStr}";
        }

        // Send a single digest email if there is anything to report.
        if (!empty($digestLines)) {
            $alertEmail = config('app.platform_alert_email', env('PLATFORM_ALERT_EMAIL'));

            if ($alertEmail) {
                $shopCount = count($digestLines);
                $body = "JewelFlow Shop Health Alert\n\n"
                    . "{$shopCount} shop(s) have a critical health score (< 30) as of " . now()->toDateTimeString() . ":\n\n"
                    . implode("\n", $digestLines) . "\n\n"
                    . "Please review shop health at /admin/shops\n\n"
                    . "---\nSent by JewelFlow platform:check-shop-health";

                try {
                    Mail::raw($body, function ($message) use ($alertEmail, $shopCount) {
                        $message->to($alertEmail)
                                ->subject("[JewelFlow] {$shopCount} Shop(s) with Critical Health Score — Action Required");
                    });
                    $this->info("Digest email sent to {$alertEmail}.");
                } catch (\Throwable $e) {
                    Log::error('CheckShopHealthAlerts: failed to send digest email: ' . $e->getMessage());
                    $this->error('Failed to send digest email: ' . $e->getMessage());
                }
            } else {
                $this->warn('PLATFORM_ALERT_EMAIL is not configured; skipping digest email.');
            }
        }

        $this->info('Shop health check complete.');

        return Command::SUCCESS;
    }
}
