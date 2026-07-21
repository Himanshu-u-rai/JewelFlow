<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression for the backup self-inclusion incident: the Spatie backup
 * destination (storage/app/private/{APP_NAME}) was never excluded from its
 * own source list, so every daily archive nested all previously stored
 * archives inside itself, causing unbounded disk growth.
 */
class BackupConfigurationTest extends TestCase
{
    public function test_source_exclusions_contain_the_resolved_current_destination(): void
    {
        $exclude = config('backup.backup.source.files.exclude');

        $resolvedDestination = storage_path('app/private/' . config('app.name'));

        $this->assertContains(
            $resolvedDestination,
            $exclude,
            'The active backup destination must be excluded from its own backup source to prevent nested self-inclusion.'
        );
    }

    public function test_source_exclusions_contain_the_legacy_destination(): void
    {
        $exclude = config('backup.backup.source.files.exclude');

        $this->assertContains(
            storage_path('app/private/JewelFlow'),
            $exclude,
            'The pre-rebrand destination (JewelFlow, before the JewelFlows rename) must also be excluded.'
        );
    }

    public function test_genuine_application_data_directories_are_not_excluded(): void
    {
        $exclude = config('backup.backup.source.files.exclude');

        // storage/app/private also holds real application data (KYC uploads,
        // import files, dhiran records) that must remain backed up. The fix
        // must exclude only the two backup-destination subfolders, never the
        // whole storage/app/private tree.
        $genuineDataPaths = [
            storage_path('app/private/kyc'),
            storage_path('app/private/imports'),
            storage_path('app/private/dhiran'),
            storage_path('app/private/invoice-parse-tmp'),
        ];

        foreach ($genuineDataPaths as $path) {
            $this->assertNotContains($path, $exclude);
        }

        $this->assertNotContains(storage_path('app/private'), $exclude);
    }
}
