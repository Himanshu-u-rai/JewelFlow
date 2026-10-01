<?php

namespace App\Console\Commands;

use App\Models\Repair;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Move repair photos off the public disk.
 *
 * Until 2026-10-01 a repair photo was written to storage/app/public/repairs/,
 * which nginx serves to anyone holding the URL. New photos go to the private
 * disk (Repair::IMAGE_DISK) and are served through routes that check who is
 * asking; this moves the ones already there. Every file under the public
 * disk's repairs/ goes to the same relative path on the private disk — whether
 * a repair names it or not — so the rows need no change and the application
 * finds each photo where it now looks first.
 *
 * A move is a rename on one file system: same bytes (sha256 checked before and
 * after), same owner, nothing copied and nothing deleted. A path the private
 * disk already holds is never overwritten: it is reported and left alone.
 *
 *   php artisan repairs:relocate-images             dry run: what would move
 *   php artisan repairs:relocate-images --execute   move
 *   php artisan repairs:relocate-images --verify    none left in public; every named photo is private
 */
class RelocateRepairImages extends Command
{
    protected $signature = 'repairs:relocate-images
        {--execute : Move the files (the default is a dry run)}
        {--verify : Check the end state: nothing left on the public disk, every named photo on the private disk}';

    protected $description = 'Move repair photos from the public disk to the private disk (same relative path, nothing deleted).';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk(Repair::IMAGE_DISK);
        $files = collect($public->allFiles('repairs'))->sort()->values();
        $named = DB::table('repairs')->get(['image', 'image_path'])
            ->flatMap(fn ($row) => [$row->image, $row->image_path])
            ->filter(fn ($path) => is_string($path) && $path !== '' && preg_match('/^https?:\/\//i', $path) !== 1)
            ->unique()->values();

        if ($this->option('verify')) {
            $missing = $named->reject(fn ($path) => $private->exists($path))->count();
            $this->line("repair photos on the public disk: {$files->count()}; named by a repair but not on the private disk: {$missing}");

            return $files->isEmpty() && $missing === 0 ? self::SUCCESS : self::FAILURE;
        }

        $moved = 0;
        $problems = 0;
        foreach ($files as $path) {
            $label = dirname($path).'/… ('.($named->contains($path) ? 'named by a repair' : 'no repair names it').')';
            $sum = hash_file('sha256', $public->path($path));
            if ($private->exists($path)) {
                $same = hash_file('sha256', $private->path($path)) === $sum;
                $this->warn("CONFLICT {$label}: the private disk already holds ".($same ? 'an identical' : 'a DIFFERENT').' file at this path; both left where they are');
                $problems++;

                continue;
            }
            if (! $this->option('execute')) {
                $this->line("would move {$label}");

                continue;
            }
            $private->makeDirectory(dirname($path));
            if (! @rename($public->path($path), $private->path($path))
                || $public->exists($path) || hash_file('sha256', $private->path($path)) !== $sum) {
                $this->error("FAILED {$label}: not moved cleanly; nothing was deleted, check both disks");
                $problems++;

                continue;
            }
            $moved++;
            $this->line("moved {$label} sha256 ".substr($sum, 0, 12));
        }

        $this->line($this->option('execute')
            ? "moved {$moved} of {$files->count()} file(s); {$problems} problem(s)"
            : "dry run: {$files->count()} file(s) on the public disk, {$problems} conflict(s); --execute moves them");

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }
}
