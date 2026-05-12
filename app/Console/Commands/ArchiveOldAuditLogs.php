<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ArchiveOldAuditLogs extends Command
{
    protected $signature = 'platform:archive-audit-logs {--days=90 : Archive logs older than this many days}';

    protected $description = 'Archive old platform audit logs to the archive table.';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $totalArchived = 0;

        $this->info("Archiving platform audit logs older than {$days} days (before {$cutoff->toDateTimeString()})...");

        do {
            $rows = DB::table('platform_audit_logs')
                ->where('created_at', '<', $cutoff)
                ->limit(500)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $ids = $rows->pluck('id')->all();

            $archiveRows = $rows->map(function ($row) {
                $data = (array) $row;
                $data['original_created_at'] = $data['created_at'];
                $data['archived_at']         = now()->toDateTimeString();
                unset($data['id']); // let archive table assign its own PK
                return $data;
            })->all();

            DB::table('platform_audit_logs_archive')->insert($archiveRows);
            DB::table('platform_audit_logs')->whereIn('id', $ids)->delete();

            $totalArchived += count($ids);
            $this->line("  Archived batch of " . count($ids) . " rows…");

        } while (true);

        $this->info("Done. Total archived: {$totalArchived} rows.");

        return self::SUCCESS;
    }
}
