<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class TriggerBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private int $logId)
    {
    }

    public function handle(): void
    {
        try {
            $dbName   = config('database.connections.pgsql.database');
            $filename = '/tmp/jwf_backup_' . date('Ymd_His') . '.sql';

            exec('pg_dump ' . escapeshellarg($dbName) . ' > ' . escapeshellarg($filename), $out, $exitCode);

            $sizeBytes = 0;
            if ($exitCode === 0 && file_exists($filename)) {
                $sizeBytes = filesize($filename) ?: 0;
            }

            DB::table('platform_backup_log')
                ->where('id', $this->logId)
                ->update([
                    'status'       => $exitCode === 0 ? 'success' : 'failed',
                    'size_bytes'   => $sizeBytes,
                    'filename'     => basename($filename),
                    'completed_at' => now(),
                    'notes'        => $exitCode !== 0 ? "pg_dump exited with code {$exitCode}" : null,
                    'updated_at'   => now(),
                ]);
        } catch (\Throwable $e) {
            DB::table('platform_backup_log')
                ->where('id', $this->logId)
                ->update([
                    'status'       => 'failed',
                    'completed_at' => now(),
                    'notes'        => $e->getMessage(),
                    'updated_at'   => now(),
                ]);
        }
    }
}
