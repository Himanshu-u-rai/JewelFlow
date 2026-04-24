<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('assets:verify-fresh', function () {
    $manifestPath = public_path('build/manifest.json');

    if (! is_file($manifestPath)) {
        $this->error('Missing Vite manifest at public/build/manifest.json. Run npm run build.');
        return 1;
    }

    $manifest = json_decode(file_get_contents($manifestPath), true);

    if (! is_array($manifest) || $manifest === []) {
        $this->error('Vite manifest is empty or unreadable. Run npm run build.');
        return 1;
    }

    $watchedPaths = [
        resource_path('css'),
        resource_path('js'),
        resource_path('views'),
        base_path('tailwind.config.js'),
        base_path('vite.config.js'),
        base_path('postcss.config.js'),
        base_path('package.json'),
    ];

    $latestSourceFile = null;
    $latestSourceTime = 0;

    $inspectPath = function (string $path) use (&$inspectPath, &$latestSourceFile, &$latestSourceTime): void {
        if (! file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            $mtime = filemtime($path) ?: 0;
            if ($mtime >= $latestSourceTime) {
                $latestSourceTime = $mtime;
                $latestSourceFile = $path;
            }

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $mtime = $file->getMTime();
            if ($mtime >= $latestSourceTime) {
                $latestSourceTime = $mtime;
                $latestSourceFile = $file->getPathname();
            }
        }
    };

    foreach ($watchedPaths as $path) {
        $inspectPath($path);
    }

    if (! $latestSourceFile || $latestSourceTime === 0) {
        $this->warn('No frontend source files were found to compare against the Vite build.');
        return 0;
    }

    $oldestBuiltFile = null;
    $oldestBuiltTime = PHP_INT_MAX;

    foreach ($manifest as $entry) {
        $builtFile = public_path('build/' . ($entry['file'] ?? ''));

        if (! is_file($builtFile)) {
            $this->error("Manifest references a missing build asset: {$builtFile}");
            return 1;
        }

        $mtime = filemtime($builtFile) ?: 0;
        if ($mtime <= $oldestBuiltTime) {
            $oldestBuiltTime = $mtime;
            $oldestBuiltFile = $builtFile;
        }
    }

    if (! $oldestBuiltFile || $oldestBuiltTime === PHP_INT_MAX) {
        $this->error('No compiled Vite assets were found to verify.');
        return 1;
    }

    if ($oldestBuiltTime < $latestSourceTime) {
        $this->error('Frontend build is stale.');
        $this->table(
            ['Type', 'File', 'Modified'],
            [
                ['latest source', $latestSourceFile, date('c', $latestSourceTime)],
                ['oldest build', $oldestBuiltFile, date('c', $oldestBuiltTime)],
            ]
        );
        $this->line('Run npm run build:verify before deploying.');

        return 1;
    }

    $this->info('Frontend build is fresh.');

    return 0;
})->purpose('Fail when compiled Vite assets are older than frontend sources');

Schedule::command('backup:run')->daily();
Schedule::command('loyalty:expire')->daily();
Schedule::command('subscription:check-expiry')->daily();
Schedule::command('scan:cleanup')->daily();
Schedule::command('cache:warm-shops')->everyTenMinutes()->withoutOverlapping();
Schedule::command('dhiran:accrue-interest')->daily();
Schedule::command('dhiran:overdue-reminders')->daily();
Schedule::command('dhiran:forfeiture-check')->daily();
