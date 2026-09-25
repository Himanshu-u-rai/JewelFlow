<?php

namespace App\Support;

/**
 * The file half of `backup:run`, as an allowlist. config/backup.php reads
 * INCLUDE; `backup:scope-check` and BackupSourceExclusionTest read both lists.
 *
 * Why an allowlist and not `include => [base_path()]` plus excludes:
 * spatie/laravel-backup walks every included directory in full and filters
 * afterwards, so an unreadable directory anywhere under base_path() (a
 * root-owned .git object dir, output/, a chowned .claude/) aborts the run
 * even when it is excluded. And an included FILE bypasses `shouldExclude()`
 * entirely. Listing what is wanted means the walk never reaches the rest.
 *
 * Every top-level path must appear in exactly one list. A new one fails
 * BackupSourceExclusionTest (tracked paths) or `backup:scope-check` (paths
 * that only exist on a server) until someone decides which list it belongs in.
 */
final class BackupScope
{
    /** Archived. Relative to base_path(). */
    public const INCLUDE = [
        // Secrets needed to restore: APP_KEY decrypts encrypted columns. The
        // ONLY env file archived; nothing else named .env* can be selected.
        '.env',

        // Uploaded files: every local disk root lives under here (the test
        // pins that). The backup destination inside it is excluded in config.
        'storage/app',

        // The application at the deployed commit, so the archive restores
        // without git. `bootstrap/cache` is excluded in config: its compiled
        // config.php is a plaintext copy of every env secret.
        'app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes',
        'artisan', 'composer.json', 'composer.lock',
        'package.json', 'package-lock.json', 'postcss.config.js', 'tailwind.config.js', 'vite.config.js',
    ];

    /** Top-level paths deliberately NOT archived. fnmatch() patterns. */
    public const NOT_ARCHIVED = [
        // Must never be archived.
        '.env.*',       // point-in-time secret snapshots (.env.save, .env.pre-*, …) and .env.example
        '.git',         // VCS metadata; root-owned object dirs have broken the walk before
        '.claude',      // agent tooling state
        'output',       // deploy/hotfix audit trail

        // Reinstalled from the lockfiles above.
        'vendor', 'node_modules',

        // Not needed to restore the running application.
        'tests', 'docs', 'bin', 'deploy', '.github', '*.md', 'phpunit.xml',
        '.editorconfig', '.gitattributes', '.gitignore', '.phpunit.result.cache',

        // Server-only tooling and test artefacts found in the production tree
        // by backup:scope-check (2026-09-25). None is needed to restore; .mcp.json
        // may hold tool credentials and must never be archived.
        '.agents', '.augment', '.mcp.json', '.npm', 'skills-lock.json', 'test-results',
    ];

    /** @return list<string> */
    public static function includePaths(string $base): array
    {
        return array_map(fn (string $path) => $base.'/'.$path, self::INCLUDE);
    }

    /** @return list<string> top-level entries of $base that neither list accounts for */
    public static function unclassified(string $base): array
    {
        return self::unclassifiedAmong(array_diff(scandir($base) ?: [], ['.', '..']));
    }

    /**
     * @param  iterable<string>  $entries  top-level names
     * @return list<string>
     */
    public static function unclassifiedAmong(iterable $entries): array
    {
        $included = array_map(fn (string $path) => explode('/', $path)[0], self::INCLUDE);

        $unclassified = [];

        foreach ($entries as $entry) {
            if (in_array($entry, $included, true)) {
                continue;
            }

            foreach (self::NOT_ARCHIVED as $pattern) {
                if (fnmatch($pattern, $entry)) {
                    continue 2;
                }
            }

            $unclassified[] = $entry;
        }

        return $unclassified;
    }
}
