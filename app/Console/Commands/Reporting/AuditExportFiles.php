<?php

namespace App\Console\Commands\Reporting;

use App\Models\Reporting\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S3-18, read-only. Which export rows name the same stored file?
 *
 * Rows are grouped by where the file physically is — the disk resolved to its
 * driver and root (or bucket), so two disk names for one place count as one —
 * and by the normalized path. A group of two or more is a CANDIDATE only:
 * metadata shows that rows share a file, not whose bytes the file holds, and
 * not that anyone downloaded it. A file only one row names can still hold
 * another export's bytes (a job that overwrote it and failed before recording
 * its path leaves no row), which is why the download route now refuses every
 * file outside its export's own directory.
 *
 * Reads the database and the disk configuration; never reads file contents
 * and never writes. Exits 1 when a cross-shop group exists.
 */
class AuditExportFiles extends Command
{
    protected $signature = 'reporting:audit-export-files';

    protected $description = 'Read-only: group queued-export rows by physical file and flag shared files (S3-18).';

    public function handle(): int
    {
        $rows = ReportExport::withoutGlobalScopes()->whereNotNull('file_path')
            ->orderBy('id')->get(['id', 'shop_id', 'user_id', 'report_key', 'status', 'file_disk', 'file_path', 'generated_at']);

        $groups = [];
        $legacy = 0;
        foreach ($rows as $row) {
            $key = $this->location((string) $row->file_disk).'|'.$this->normalize((string) $row->file_path);
            $groups[$key][] = $row;
            $legacy += $row->fileIsInOwnDirectory() ? 0 : 1;
        }

        $shared = array_filter($groups, fn ($g) => count($g) > 1);
        $crossShop = array_filter($shared, fn ($g) => count(array_unique(array_map(fn ($r) => (int) $r->shop_id, $g))) > 1);

        $this->line('export rows with a file: '.$rows->count());
        $this->line('  outside their own directory (refused at download since S3-18): '.$legacy);
        $this->line('shared files: '.count($shared).' — same shop only: '.(count($shared) - count($crossShop)).', CROSS-SHOP: '.count($crossShop));
        $this->line('disk locations: '.json_encode($this->locations()));
        $this->line('notifications table: '.(Schema::hasTable('notifications') ? 'present' : 'ABSENT (no export-ready notification can have been stored)'));
        if (Schema::hasColumn('report_exports', 'notified_at')) {
            $this->line('finished queued exports not recorded as notified: '.ReportExport::withoutGlobalScopes()
                ->where('mode', 'queued')->where('status', 'done')->whereNull('notified_at')->count());
        }

        foreach ($crossShop as $key => $group) {
            [$location, $path] = explode('|', $key, 2);
            $this->warn("cross-shop: {$location} {$path}");
            foreach ($group as $row) {
                $this->line(sprintf('  export %d  shop %d  user %s  %s  %s  generated %s  notifications %s',
                    $row->id, $row->shop_id, $row->user_id ?? '-', $row->report_key, $row->status,
                    $row->generated_at, $this->notificationCount((int) $row->id)));
            }
        }

        $this->line('What this cannot tell: whose bytes a shared file holds, and whether any row was downloaded — '
            .'the application does not log downloads; the web server access log for /reporting/exports/{id}/download does.');

        return $crossShop === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Driver plus root or bucket, so aliases of one location group together. */
    private function location(string $disk): string
    {
        $config = config("filesystems.disks.{$disk}");
        if (! is_array($config)) {
            return "unresolved:{$disk}";
        }

        return match ($config['driver'] ?? null) {
            'local' => 'local:'.rtrim((string) (realpath((string) $config['root']) ?: $config['root']), '/'),
            's3' => 's3:'.($config['bucket'] ?? '?').'/'.trim((string) ($config['root'] ?? ''), '/'),
            default => ($config['driver'] ?? '?').':'.$disk,
        };
    }

    private function locations(): array
    {
        $out = [];
        foreach (array_keys((array) config('filesystems.disks')) as $disk) {
            $out[$disk] = $this->location($disk);
        }

        return $out;
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        $path = preg_replace('#(^|/)\./#', '$1', $path);

        return ltrim($path, '/');
    }

    private function notificationCount(int $exportId): string
    {
        if (! Schema::hasTable('notifications')) {
            return 'n/a';
        }

        // `data` is text in Laravel's schema: cast before reading the key.
        return (string) DB::table('notifications')->whereRaw("(data::jsonb ->> 'export_id') = ?", [(string) $exportId])->count();
    }
}
