<?php

namespace App\Console\Commands;

use App\Services\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One-time backfill: convert already-stored images to resized WebP to reclaim
 * disk space. New uploads are handled by ImageOptimizer at upload time; this
 * sweeps the files that pre-date that. Per-row it converts, repoints the DB
 * column to the new .webp path, then (unless --keep-originals) deletes the old
 * file. Idempotent: files that are already WebP within bounds are skipped.
 *
 * Only touches DISPLAY images. Ledger/document stores (KYC, karigar invoices)
 * are intentionally excluded.
 */
class OptimizeExistingImages extends Command
{
    protected $signature = 'images:optimize-existing
        {--dry-run : Report what would change without writing anything}
        {--keep-originals : Keep the original files instead of deleting them}';

    protected $description = 'Convert existing stored display images to resized WebP to reclaim disk space.';

    /** @var array<int, array{table:string, singles:string[], jsons:string[]}> */
    private array $targets = [
        ['table' => 'items', 'singles' => ['image'], 'jsons' => ['images']],
        ['table' => 'products', 'singles' => ['image'], 'jsons' => []],
        ['table' => 'repairs', 'singles' => ['image'], 'jsons' => []],
        ['table' => 'shops', 'singles' => ['logo_path'], 'jsons' => []],
        ['table' => 'shop_billing_settings', 'singles' => ['digital_signature_path'], 'jsons' => []],
        ['table' => 'catalog_website_settings', 'singles' => ['hero_image_path'], 'jsons' => []],
        ['table' => 'stock_purchases', 'singles' => ['invoice_image'], 'jsons' => []],
    ];

    private int $converted = 0;
    private int $skipped = 0;
    private int $candidateBytes = 0;
    private int $newBytes = 0;

    public function handle(ImageOptimizer $optimizer): int
    {
        $dry = (bool) $this->option('dry-run');
        $keep = (bool) $this->option('keep-originals');
        $disk = Storage::disk('public');

        foreach ($this->targets as $t) {
            $cols = array_merge($t['singles'], $t['jsons']);
            $rows = DB::table($t['table'])->select(array_merge(['id'], $cols))->get();

            foreach ($rows as $row) {
                $update = [];
                // Per-row map of old path -> new path, so a path shared between a
                // single column and a json array (e.g. items.image === images[0])
                // is converted once and both columns get repointed to the same new
                // file instead of one of them being left pointing at a deleted file.
                $rowMap = [];

                foreach ($t['singles'] as $col) {
                    $old = trim((string) ($row->$col ?? ''));
                    $res = $this->convertPath($optimizer, $disk, $old, $dry, $keep, $rowMap);
                    if ($res['action'] === 'replace') {
                        $update[$col] = $res['path'];
                    }
                }

                foreach ($t['jsons'] as $col) {
                    $arr = $this->decodeArray($row->$col ?? null);
                    if (! $arr) {
                        continue;
                    }
                    $out = [];
                    foreach ($arr as $p) {
                        $res = $this->convertPath($optimizer, $disk, trim((string) $p), $dry, $keep, $rowMap);
                        // 'replace' / 'keep' -> retain (converted or as-is);
                        // 'drop' -> the file is gone, so remove the dead reference.
                        if ($res['action'] !== 'drop') {
                            $out[] = $res['path'];
                        }
                    }
                    $out = array_values(array_unique($out));
                    if ($out !== array_values($arr)) {
                        $update[$col] = json_encode($out);
                    }
                }

                if (! $dry && ! empty($update)) {
                    DB::table($t['table'])->where('id', $row->id)->update($update);
                }
            }
        }

        if ($dry) {
            $this->info(sprintf(
                '[DRY RUN] would convert %d image(s) (%.2f MB currently); %d skipped (already optimal / not an image).',
                $this->converted,
                $this->candidateBytes / 1048576,
                $this->skipped,
            ));
        } else {
            $this->info(sprintf(
                'Done. converted=%d skipped=%d  %.2f MB -> %.2f MB  (reclaimed %.2f MB)',
                $this->converted,
                $this->skipped,
                $this->candidateBytes / 1048576,
                $this->newBytes / 1048576,
                max(0, $this->candidateBytes - $this->newBytes) / 1048576,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Resolve one stored path. Returns an action + the path to use:
     *   ['action' => 'replace', 'path' => <new webp>]  converted to webp
     *   ['action' => 'keep',    'path' => <original>]   valid, nothing to gain
     *   ['action' => 'drop',    'path' => '']           file is missing/empty
     *
     * @param array<string,string> $rowMap old path -> already-converted new path
     * @return array{action:string, path:string}
     */
    private function convertPath(ImageOptimizer $optimizer, $disk, string $path, bool $dry, bool $keep, array &$rowMap): array
    {
        if ($path === '') {
            return ['action' => 'drop', 'path' => ''];
        }

        // Already converted this run via another column on the same row.
        if (isset($rowMap[$path])) {
            return ['action' => 'replace', 'path' => $rowMap[$path]];
        }

        if (! $disk->exists($path)) {
            return ['action' => 'drop', 'path' => ''];
        }

        $bytes = $disk->get($path);
        if ($bytes === null || $bytes === '') {
            return ['action' => 'drop', 'path' => ''];
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            $this->skipped++; // not a raster image (svg/pdf/etc.) — keep as-is
            return ['action' => 'keep', 'path' => $path];
        }

        // Already WebP and within bounds -> nothing to gain, keep as-is.
        if (($info['mime'] ?? '') === 'image/webp' && max($info[0], $info[1]) <= ImageOptimizer::MAX_EDGE) {
            $this->skipped++;
            return ['action' => 'keep', 'path' => $path];
        }

        $this->candidateBytes += strlen($bytes);

        if ($dry) {
            $this->converted++;
            return ['action' => 'keep', 'path' => $path];
        }

        $dir = trim(dirname($path), '/.');
        $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg';

        $newPath = $optimizer->optimizeAndStoreBinary($bytes, $dir === '' ? 'misc' : $dir, 'public', $ext);
        if ($newPath === $path) {
            return ['action' => 'keep', 'path' => $path];
        }

        $this->newBytes += strlen((string) $disk->get($newPath));
        $this->converted++;
        $rowMap[$path] = $newPath;

        if (! $keep) {
            $disk->delete($path);
        }

        return ['action' => 'replace', 'path' => $newPath];
    }

    private function decodeArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
