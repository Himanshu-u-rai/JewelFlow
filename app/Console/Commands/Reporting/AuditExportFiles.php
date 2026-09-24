<?php

namespace App\Console\Commands\Reporting;

use App\Models\Reporting\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S3-18, read-only. Which export rows name the same stored file?
 *
 * Each row's file is resolved to a physical key from THIS host's storage
 * configuration at run time: a local disk to the canonical absolute path
 * (root and path through realpath, so overlapping roots and alias disks
 * meet); an s3 disk to endpoint + bucket + prefix + path (one bucket name on
 * two endpoints is two stores); a scoped disk through the disk it wraps.
 * Anything else — an adapter this command does not model, a disk the
 * configuration does not define, a path that climbs above its root — is
 * UNRESOLVED: its sharing is unknown, never reported clean.
 *
 * A group of two or more rows is a CANDIDATE only: metadata shows that rows
 * share a file, not whose bytes the file holds, and not that anyone read it.
 * A file one row names can still hold another export's bytes (a job that
 * overwrote it and failed before recording its path leaves no row), which is
 * why the download route refuses every file outside its export's own
 * directory.
 *
 * Reads the database and the disk configuration; never reads file contents
 * and never writes. Exits 1 when a cross-shop group exists, 2 when none does
 * but some rows could not be resolved, 0 only when every row was resolved
 * and no file is shared across shops.
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
        $unresolved = [];
        $legacy = 0;
        foreach ($rows as $row) {
            $legacy += $row->fileIsInOwnDirectory() ? 0 : 1;
            [$key, $why] = $this->physical((string) $row->file_disk, (string) $row->file_path);
            if ($key === null) {
                $unresolved["{$row->file_disk}: {$why}"][] = $row;
            } else {
                $groups[$key][] = $row;
            }
        }

        $shared = array_filter($groups, fn ($g) => count($g) > 1);
        $crossShop = array_filter($shared, fn ($g) => count(array_unique(array_map(fn ($r) => (int) $r->shop_id, $g))) > 1);
        $unresolvedCount = array_sum(array_map('count', $unresolved));

        $this->line('export rows with a file: '.$rows->count().' — resolved to a physical file: '.($rows->count() - $unresolvedCount).', unresolved: '.$unresolvedCount);
        $this->line('  outside their own directory (refused at download since S3-18): '.$legacy);
        $this->line('shared files among resolved rows: '.count($shared).' — same shop only: '.(count($shared) - count($crossShop)).', CROSS-SHOP: '.count($crossShop));
        foreach ($unresolved as $reason => $group) {
            $this->warn("UNRESOLVED ({$reason}): ".count($group).' row(s) — whether their files are shared is UNKNOWN, not clean');
        }
        $this->line('storage as configured on this host now: '.json_encode($this->locations(), JSON_UNESCAPED_SLASHES));
        $this->line('notifications table present now: '.(Schema::hasTable('notifications') ? 'yes' : 'no')
            .' (current configuration only — it says nothing about what was stored, read or deleted before)');
        if (Schema::hasColumn('report_exports', 'notified_at')) {
            $this->line('finished queued exports not recorded as notified: '.ReportExport::withoutGlobalScopes()
                ->where('mode', 'queued')->where('status', 'done')->whereNull('notified_at')->count());
        }

        foreach ($crossShop as $key => $group) {
            $this->warn("cross-shop: {$key}");
            foreach ($group as $row) {
                $this->line(sprintf('  export %d  shop %d  user %s  %s  %s  disk %s  path %s  generated %s  notifications naming it now %s',
                    $row->id, $row->shop_id, $row->user_id ?? '-', $row->report_key, $row->status, $row->file_disk, $row->file_path,
                    $row->generated_at, $this->notificationCount((int) $row->id)));
            }
        }

        $this->line('What this cannot tell: whose bytes a shared file holds; whether any row was downloaded (the application '
            .'logs no downloads — the web server access log for /reporting/exports/{id}/download does); anything about storage '
            .'configured differently in the past, or on another host.');

        return $crossShop !== [] ? self::FAILURE : ($unresolved !== [] ? 2 : self::SUCCESS);
    }

    /**
     * The physical file a disk + path names on this host's configuration, or
     * [null, why] when it cannot be resolved.
     *
     * @return array{0: ?string, 1: string}
     */
    private function physical(string $disk, string $path, int $depth = 0): array
    {
        $config = config("filesystems.disks.{$disk}");
        if (! is_array($config)) {
            return [null, 'no such disk in this configuration'];
        }
        $relative = $this->normalize($path);
        if ($relative === null) {
            return [null, 'the path climbs above its root'];
        }

        switch ($config['driver'] ?? null) {
            case 'local':
                $base = $this->localRoot($config);
                if ($base === null) {
                    return [null, 'local disk without a root'];
                }
                $full = $base.'/'.$relative;

                return ['file://'.(realpath($full) ?: $full), ''];
            case 's3':
                if (empty($config['bucket'])) {
                    return [null, 's3 disk without a bucket'];
                }
                $prefix = trim((string) ($config['root'] ?? ''), '/');

                return [$this->s3Store($config).'/'.($prefix !== '' ? $prefix.'/' : '').$relative, ''];
            case 'scoped':
                if ($depth > 3 || empty($config['disk'])) {
                    return [null, 'scoped disk that cannot be followed'];
                }
                $prefix = trim((string) ($config['prefix'] ?? ''), '/');

                return $this->physical((string) $config['disk'], ($prefix !== '' ? $prefix.'/' : '').$relative, $depth + 1);
            default:
                return [null, "adapter '".($config['driver'] ?? '?')."' is not resolved by this command"];
        }
    }

    private function localRoot(array $config): ?string
    {
        $root = (string) ($config['root'] ?? '');
        if ($root === '') {
            return null;
        }

        return rtrim(realpath($root) ?: $root, '/');
    }

    /** Endpoint and bucket: the same bucket name on two endpoints is two stores. */
    private function s3Store(array $config): string
    {
        $endpoint = rtrim((string) ($config['endpoint'] ?? ''), '/') ?: 'aws/'.($config['region'] ?? '?');

        return 's3://'.preg_replace('#^https?://#', '', $endpoint).'/'.$config['bucket'];
    }

    /** Each configured disk as this command resolves it. */
    private function locations(): array
    {
        $out = [];
        foreach ((array) config('filesystems.disks') as $disk => $config) {
            $out[$disk] = match ($config['driver'] ?? null) {
                'local' => ($root = $this->localRoot($config)) ? 'file://'.$root : 'UNRESOLVED (no root)',
                's3' => empty($config['bucket']) ? 'UNRESOLVED (no bucket)'
                    : $this->s3Store($config).(($p = trim((string) ($config['root'] ?? ''), '/')) !== '' ? '/'.$p : ''),
                'scoped' => 'scoped over '.($config['disk'] ?? '?').'/'.trim((string) ($config['prefix'] ?? ''), '/'),
                default => 'UNRESOLVED (adapter '.($config['driver'] ?? '?').')',
            };
        }

        return $out;
    }

    /** Forward slashes, no empty or "." segments, ".." resolved; null if it climbs above the root. */
    private function normalize(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    private function notificationCount(int $exportId): string
    {
        if (! Schema::hasTable('notifications')) {
            return 'n/a (no table now)';
        }

        // `data` is text in Laravel's schema: cast before reading the key.
        return (string) DB::table('notifications')->whereRaw("(data::jsonb ->> 'export_id') = ?", [(string) $exportId])->count();
    }
}
