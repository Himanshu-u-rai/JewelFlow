<?php

namespace Tests\Feature;

use App\Support\BackupScope;
use Illuminate\Support\Facades\Artisan;
use Spatie\Backup\Tasks\Backup\FileSelection;
use Symfony\Component\Finder\Exception\AccessDeniedException;
use Tests\TestCase;

/**
 * What `backup:run` archives, proven against the REAL config/backup.php.
 *
 * History. The source list used to be `include => [base_path()]` plus a
 * growing exclude list, and it failed twice in the same way:
 *
 *   1. ZipArchive::close(): Permission denied — root-owned, mode-600 secret
 *      snapshots (.env.save, .env.pre-*) and .claude/settings.json inside the
 *      walked tree. Fixed at the time by adding them to `exclude`.
 *   2. A root-owned 0700 directory (.git/objects/xx, output/) broke the walk
 *      before any exclusion was consulted.
 *
 * Both follow from how spatie/laravel-backup 9.3.6 selects files
 * (Tasks/Backup/FileSelection.php):
 *
 *   * an included DIRECTORY is walked in full by Finder and `shouldExclude()`
 *     is applied to what the walk yields — so an unreadable directory throws
 *     even when it is excluded;
 *   * an included FILE is yielded directly and never reaches `shouldExclude()`.
 *
 * The scope is therefore an allowlist (App\Support\BackupScope): the walk
 * never enters .git, .claude, output, vendor or node_modules, and no env file
 * other than `.env` can be selected, because nothing selects it.
 *
 * The earlier version of this file tested a hand-copied exclude list, not the
 * config, so it stayed green whatever config/backup.php said. Every selection
 * below evaluates the real config file with base_path() pointed at a synthetic
 * tree.
 */
class BackupSourceExclusionTest extends TestCase
{
    private string $fixtureBase;

    /** Directories made unreadable for a test; restored before cleanup. */
    private array $lockedDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureBase = storage_path('framework/testing/backup-source-fixture');

        $this->resetFixture();
        mkdir($this->fixtureBase, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->resetFixture();

        parent::tearDown();
    }

    private function resetFixture(): void
    {
        foreach ($this->lockedDirectories as $dir) {
            @chmod($dir, 0755);
        }
        $this->lockedDirectories = [];

        if (is_dir($this->fixtureBase)) {
            $this->deleteDirectory($this->fixtureBase);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir.DIRECTORY_SEPARATOR.$item;

            is_dir($path) && ! is_link($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function putFixtureFile(string $relativePath): string
    {
        $fullPath = $this->fixtureBase.DIRECTORY_SEPARATOR.$relativePath;

        @mkdir(dirname($fullPath), 0755, true);

        file_put_contents($fullPath, "harmless fixture content\n");

        return $fullPath;
    }

    /** chmod 000 — the root-owned 0700 directory as the www-data scheduler sees it. */
    private function lockDirectory(string $relativePath): void
    {
        $dir = $this->fixtureBase.DIRECTORY_SEPARATOR.$relativePath;
        chmod($dir, 0000);
        $this->lockedDirectories[] = $dir;

        if (is_readable($dir)) {
            $this->markTestSkipped("Running as a user that can read a mode-000 directory (root?); {$relativePath} cannot be made unreadable.");
        }
    }

    /** The files section of the real config/backup.php, evaluated at $base. */
    private function realConfigAt(string $base): array
    {
        $configFile = config_path('backup.php');
        $realBase = $this->app->basePath();

        $this->app->setBasePath($base);

        try {
            return (require $configFile)['backup']['source']['files'];
        } finally {
            $this->app->setBasePath($realBase);
        }
    }

    /** Built exactly as BackupJobFactory::createFileSelection builds it. */
    private function selectFixtureFiles(): array
    {
        $files = $this->realConfigAt($this->fixtureBase);

        $selection = FileSelection::create($files['include'])
            ->excludeFilesFrom($files['exclude'])
            ->shouldFollowLinks($files['follow_links'])
            ->shouldIgnoreUnreadableDirs($files['ignore_unreadable_directories']);

        $selected = [];

        foreach ($selection->selectedFiles() as $file) {
            $selected[] = realpath($file);
        }

        return $selected;
    }

    /** A plausible production tree: everything that exists at the top level today. */
    private function populateProductionShapedTree(): void
    {
        foreach ([
            '.env',
            '.env.example', '.env.save', '.env.testing', '.env.backup',
            '.env.pre-subscription-alert-email-20260813T094745Z',
            'artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
            'vite.config.js', 'tailwind.config.js', 'postcss.config.js', 'phpunit.xml', 'CONSTITUTION.md',
            'app/Http/Controllers/PosController.php',
            'bootstrap/app.php', 'bootstrap/cache/config.php',
            'config/app.php', 'database/migrations/0001_create.php', 'lang/en/auth.php',
            'public/index.php', 'resources/views/welcome.blade.php', 'routes/web.php',
            'storage/app/public/signatures/7/sig.png',
            'storage/app/private/kyc/7/pan.pdf',
            'storage/app/private/'.env('APP_NAME', 'laravel-backup').'/2026-08-07-00-00-02.zip',
            'storage/app/private/JewelFlow/2026-06-13-00-00-02.zip',
            'storage/framework/sessions/abc123',
            'storage/logs/laravel.log',
            'vendor/some-package/File.php', 'node_modules/pkg/index.js',
            'tests/Feature/ExampleTest.php', 'docs/runbooks/x.md',
            '.git/HEAD', '.git/objects/eb/deadbeef',
            '.claude/settings.json',
            'output/audit/staging-hotfix/deploy-staging-hotfix.sh',
        ] as $path) {
            $this->putFixtureFile($path);
        }
    }

    private function fixture(string $relativePath): string
    {
        return realpath($this->fixtureBase.DIRECTORY_SEPARATOR.$relativePath);
    }

    // ────────────────────────────────────────────────────────────────────
    // The known traversal failure
    // ────────────────────────────────────────────────────────────────────

    /**
     * [FIXTURE CONTROL] The synthetic tree reproduces the failure: the old
     * shape — walk base_path(), unreadable directories not ignored — throws.
     * Without this, the next test could pass on a fixture that never had an
     * unreadable directory in it.
     */
    public function test_control_walking_the_whole_base_path_throws_on_an_unreadable_directory(): void
    {
        $this->populateProductionShapedTree();
        $this->lockDirectory('.git/objects/eb');
        $this->lockDirectory('.claude');
        $this->lockDirectory('output');

        $this->expectException(AccessDeniedException::class);

        iterator_to_array(FileSelection::create([$this->fixtureBase])->selectedFiles(), false);
    }

    public function test_unreadable_git_claude_and_output_directories_do_not_break_the_selection(): void
    {
        $this->populateProductionShapedTree();
        $this->lockDirectory('.git/objects/eb');
        $this->lockDirectory('.claude');
        $this->lockDirectory('output');

        $selected = $this->selectFixtureFiles();

        $this->assertContains($this->fixture('.env'), $selected, 'the selection completed');
    }

    public function test_the_rejected_workaround_stays_off(): void
    {
        $this->assertFalse(
            config('backup.backup.source.files.ignore_unreadable_directories'),
            'ignore_unreadable_directories was explicitly rejected: it turns an unreadable '
                .'directory of real data into a silently incomplete archive.',
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // Restore content is preserved
    // ────────────────────────────────────────────────────────────────────

    public function test_restore_content_is_archived(): void
    {
        $this->populateProductionShapedTree();

        $selected = $this->selectFixtureFiles();

        foreach ([
            '.env',
            'storage/app/public/signatures/7/sig.png',
            'storage/app/private/kyc/7/pan.pdf',
            'app/Http/Controllers/PosController.php',
            'bootstrap/app.php', 'config/app.php', 'database/migrations/0001_create.php',
            'lang/en/auth.php', 'public/index.php', 'resources/views/welcome.blade.php', 'routes/web.php',
            'artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
            'vite.config.js', 'tailwind.config.js', 'postcss.config.js',
        ] as $path) {
            $this->assertContains($this->fixture($path), $selected, "{$path} is restore content");
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Only the intended environment file; no secret snapshots
    // ────────────────────────────────────────────────────────────────────

    public function test_only_the_live_env_file_is_selected(): void
    {
        $this->populateProductionShapedTree();

        $selectedEnvFiles = array_values(array_filter(
            $this->selectFixtureFiles(),
            fn (string $path) => str_starts_with(basename($path), '.env'),
        ));

        $this->assertSame([$this->fixture('.env')], $selectedEnvFiles);
    }

    /**
     * `.env.backup` was never on the old exclude list, so under the old scope
     * it would have been archived. It is here to show the allowlist handles a
     * snapshot name nobody anticipated, rather than only the ones listed.
     */
    public function test_secret_snapshots_are_not_selected(): void
    {
        $this->populateProductionShapedTree();

        $selected = $this->selectFixtureFiles();

        foreach ([
            '.env.save', '.env.testing', '.env.backup', '.env.example',
            '.env.pre-subscription-alert-email-20260813T094745Z',
            'bootstrap/cache/config.php',
            'storage/framework/sessions/abc123',
        ] as $path) {
            $this->assertNotContains($this->fixture($path), $selected, "{$path} must not be archived");
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Everything else stays out
    // ────────────────────────────────────────────────────────────────────

    public function test_tooling_vcs_agent_state_and_backup_destinations_are_not_selected(): void
    {
        $this->populateProductionShapedTree();

        $selected = $this->selectFixtureFiles();

        foreach ([
            '.git/HEAD', '.git/objects/eb/deadbeef',
            '.claude/settings.json',
            'output/audit/staging-hotfix/deploy-staging-hotfix.sh',
            'vendor/some-package/File.php', 'node_modules/pkg/index.js',
            'storage/app/private/'.env('APP_NAME', 'laravel-backup').'/2026-08-07-00-00-02.zip',
            'storage/app/private/JewelFlow/2026-06-13-00-00-02.zip',
            'storage/logs/laravel.log',
            'tests/Feature/ExampleTest.php', 'docs/runbooks/x.md', 'phpunit.xml', 'CONSTITUTION.md',
        ] as $path) {
            $this->assertNotContains($this->fixture($path), $selected, "{$path} must not be archived");
        }
    }

    public function test_the_scope_survives_config_cache(): void
    {
        Artisan::call('config:cache');

        try {
            $include = config('backup.backup.source.files.include');

            $this->assertSame(BackupScope::includePaths(base_path()), $include);
            $this->assertNotContains(base_path(), $include, 'the whole tree must never be walked again');
        } finally {
            Artisan::call('config:clear');
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Future additions are detected, not silently dropped or archived
    // ────────────────────────────────────────────────────────────────────

    /** Fails when a commit adds a top-level path nobody has classified. */
    public function test_every_tracked_top_level_path_is_classified(): void
    {
        exec('git -C '.escapeshellarg(base_path()).' ls-files 2>/dev/null', $tracked, $exit);

        if ($exit !== 0 || $tracked === []) {
            $this->markTestSkipped('Not a git checkout.');
        }

        $topLevel = array_values(array_unique(array_map(fn (string $p) => explode('/', $p)[0], $tracked)));

        $this->assertSame([], BackupScope::unclassifiedAmong($topLevel),
            'Classify each new top-level path in App\Support\BackupScope: INCLUDE if a restore needs it, '
                .'NOT_ARCHIVED (with the reason) if not.');
    }

    /** Fails when a new local disk would put uploads outside the archive. */
    public function test_every_local_disk_root_is_inside_the_archive(): void
    {
        $include = BackupScope::includePaths(base_path());

        foreach (config('filesystems.disks') as $name => $disk) {
            if (($disk['driver'] ?? null) !== 'local') {
                continue;
            }

            $root = rtrim($disk['root'], '/').'/';

            $this->assertTrue(
                collect($include)->contains(fn (string $path) => str_starts_with($root, rtrim($path, '/').'/')),
                "Local disk '{$name}' ({$disk['root']}) holds files outside the backup scope.",
            );
        }
    }

    /** The same check on a live server, where untracked additions actually appear. */
    public function test_scope_check_flags_an_unclassified_top_level_entry(): void
    {
        $this->populateProductionShapedTree();

        $this->artisan('backup:scope-check', ['path' => $this->fixtureBase])->assertExitCode(0);

        $this->putFixtureFile('customer-export-2026.csv');

        $this->artisan('backup:scope-check', ['path' => $this->fixtureBase])
            ->expectsOutputToContain('customer-export-2026.csv')
            ->assertExitCode(1);
    }
}
