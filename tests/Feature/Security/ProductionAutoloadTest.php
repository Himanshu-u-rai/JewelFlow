<?php

namespace Tests\Feature\Security;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Production installs with --no-dev: the Tests\ namespace, Faker, Mockery and
 * PHPUnit do not exist there. Artisan loads every command class, so a single
 * reference from production code to test code stops every artisan command —
 * migrations, caches, the scheduler. It did: PaymentRaceHarness used a test
 * trait and fataled the first staging deploy of this batch at its first
 * artisan call. Local runs and dev-installed runs cannot show it.
 *
 * Allowed: a reference guarded by class_exists() (bootstrap/app.php registers
 * the harness command only where the dev autoloader exists).
 */
class ProductionAutoloadTest extends TestCase
{
    public function test_production_code_does_not_depend_on_dev_only_code(): void
    {
        $offending = [];
        foreach (['app', 'bootstrap', 'config', 'routes', 'database/migrations'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/bootstrap/cache/')) {
                    continue;
                }
                foreach (file($file->getPathname()) as $n => $line) {
                    if (preg_match('/(^|[^A-Za-z_])(Tests|Faker|PHPUnit)\\\\|\bMockery\b|\bfake\(\)/', $line) && ! str_contains($line, 'class_exists(')) {
                        $offending[] = substr($file->getPathname(), strlen(base_path()) + 1).':'.($n + 1).': '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $offending, "production code references dev-only code:\n".implode("\n", $offending));
    }
}
