<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Spatie\Backup\Tasks\Backup\FileSelection;
use Tests\TestCase;

/**
 * Regression for the backup close-time permission incident: root-owned,
 * mode-600 files (.claude/settings.json, .env.save, .env.testing, and
 * .env.pre-* snapshots) sat inside the fully-included base_path() tree.
 * ZipArchive::addFile() defers the actual read to close(), so the
 * unreadable source only surfaced as a misleading
 * "ZipArchive::close(): Permission denied" once the scheduled backup
 * reached one of them, aborting the whole archive.
 *
 * These tests exercise Spatie's real FileSelection class (glob-based
 * exclusion, resolved fresh on every run) against an isolated fixture
 * tree, so they prove the *effective* selection behaviour rather than
 * just the raw config array.
 */
class BackupSourceExclusionTest extends TestCase
{
    private string $fixtureBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureBase = storage_path('framework/testing/backup-source-fixture');

        $this->resetFixture();
    }

    protected function tearDown(): void
    {
        $this->resetFixture();

        parent::tearDown();
    }

    private function resetFixture(): void
    {
        if (is_dir($this->fixtureBase)) {
            $this->deleteDirectory($this->fixtureBase);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$item;

            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function putFixtureFile(string $relativePath): void
    {
        $fullPath = $this->fixtureBase.DIRECTORY_SEPARATOR.$relativePath;

        @mkdir(dirname($fullPath), 0755, true);

        file_put_contents($fullPath, "harmless fixture content\n");
    }

    /**
     * Builds the same four exclusion entries added to config/backup.php,
     * plus the pre-existing exclusions they must not disturb, all rooted
     * at the isolated fixture directory instead of the real base_path().
     */
    private function exclusionsFor(string $base): array
    {
        return [
            $base.'/vendor',
            $base.'/node_modules',
            $base.'/storage/app/private/'.config('app.name'),
            $base.'/storage/app/private/JewelFlow',

            $base.'/.claude',
            $base.'/.env.save',
            $base.'/.env.testing',
            $base.'/.env.pre-*',
        ];
    }

    private function selectFixtureFiles(): array
    {
        $selection = FileSelection::create([$this->fixtureBase])
            ->excludeFilesFrom($this->exclusionsFor($this->fixtureBase));

        $selected = [];

        foreach ($selection->selectedFiles() as $file) {
            $selected[] = realpath($file);
        }

        return $selected;
    }

    public function test_normal_application_file_is_included(): void
    {
        $this->putFixtureFile('app/Http/Controllers/PosController.php');

        $selected = $this->selectFixtureFiles();

        $this->assertContains(
            realpath($this->fixtureBase.'/app/Http/Controllers/PosController.php'),
            $selected
        );
    }

    public function test_claude_directory_is_excluded(): void
    {
        $this->putFixtureFile('.claude/settings.json');
        $this->putFixtureFile('.claude/skills/foo.md');

        $selected = $this->selectFixtureFiles();

        $this->assertNotContains(realpath($this->fixtureBase.'/.claude/settings.json'), $selected);
        $this->assertNotContains(realpath($this->fixtureBase.'/.claude/skills/foo.md'), $selected);
    }

    public function test_env_save_is_excluded(): void
    {
        $this->putFixtureFile('.env.save');

        $selected = $this->selectFixtureFiles();

        $this->assertNotContains(realpath($this->fixtureBase.'/.env.save'), $selected);
    }

    public function test_env_testing_is_excluded(): void
    {
        $this->putFixtureFile('.env.testing');

        $selected = $this->selectFixtureFiles();

        $this->assertNotContains(realpath($this->fixtureBase.'/.env.testing'), $selected);
    }

    public function test_env_pre_star_glob_excludes_differently_named_snapshots(): void
    {
        $this->putFixtureFile('.env.pre-subscription-alert-email-20260813T094745Z');
        $this->putFixtureFile('.env.pre-test-key-rotation-20260813T075603');

        $selected = $this->selectFixtureFiles();

        $this->assertNotContains(
            realpath($this->fixtureBase.'/.env.pre-subscription-alert-email-20260813T094745Z'),
            $selected
        );
        $this->assertNotContains(
            realpath($this->fixtureBase.'/.env.pre-test-key-rotation-20260813T075603'),
            $selected
        );
    }

    public function test_live_env_file_is_not_excluded(): void
    {
        $this->putFixtureFile('.env');

        $selected = $this->selectFixtureFiles();

        $this->assertContains(realpath($this->fixtureBase.'/.env'), $selected);
    }

    public function test_existing_backup_destination_exclusions_are_preserved(): void
    {
        $this->putFixtureFile('storage/app/private/JewelFlow/2026-06-13-00-00-02.zip');
        $this->putFixtureFile('storage/app/private/'.config('app.name').'/2026-08-07-00-00-02.zip');
        $this->putFixtureFile('vendor/some-package/File.php');

        $selected = $this->selectFixtureFiles();

        $this->assertNotContains(
            realpath($this->fixtureBase.'/storage/app/private/JewelFlow/2026-06-13-00-00-02.zip'),
            $selected
        );
        $this->assertNotContains(
            realpath($this->fixtureBase.'/storage/app/private/'.config('app.name').'/2026-08-07-00-00-02.zip'),
            $selected
        );
        $this->assertNotContains(
            realpath($this->fixtureBase.'/vendor/some-package/File.php'),
            $selected
        );
    }

    public function test_new_exclusions_survive_config_cache(): void
    {
        Artisan::call('config:cache');

        try {
            $exclude = config('backup.backup.source.files.exclude');

            $this->assertContains(base_path('.claude'), $exclude);
            $this->assertContains(base_path('.env.save'), $exclude);
            $this->assertContains(base_path('.env.testing'), $exclude);
            $this->assertContains(base_path('.env.pre-*'), $exclude);

            // The live .env must never be added to the exclude list.
            $this->assertNotContains(base_path('.env'), $exclude);
        } finally {
            Artisan::call('config:clear');
        }
    }
}
