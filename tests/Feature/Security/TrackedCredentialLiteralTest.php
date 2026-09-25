<?php

namespace Tests\Feature\Security;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * S3-22: phpunit.xml carried a database password that production also used.
 * Rotation makes the leaked value useless; these checks keep a live one from
 * being committed again. Tests read DB_PASSWORD from the untracked .env.
 */
class TrackedCredentialLiteralTest extends TestCase
{
    /** The deployed baseline; its phpunit.xml carried production's password. */
    private const LEAKED_AT = '018b3d810e37d534f498033ab582ee41f3197c27';

    public function test_phpunit_config_sets_no_database_password(): void
    {
        // A boolean, so a failure never prints the file (and the value) to a log.
        $this->assertSame(0, preg_match(
            '/name="DB_PASSWORD"\s+value="[^"]+"/',
            file_get_contents(base_path('phpunit.xml')),
        ), 'phpunit.xml sets DB_PASSWORD to a value');
    }

    public function test_the_leaked_value_is_in_no_tracked_file(): void
    {
        $show = new Process(['git', 'show', self::LEAKED_AT.':phpunit.xml'], base_path());
        $show->run();
        if (! $show->isSuccessful()) {
            $this->markTestSkipped('no git history here to read the leaked value from');
        }
        preg_match('/name="DB_PASSWORD"\s+value="([^"]+)"/', $show->getOutput(), $m);
        $this->assertNotEmpty($m[1] ?? null);

        // The value goes to git on stdin, never as an argument.
        $grep = new Process(['git', 'grep', '-lF', '-f', '-'], base_path());
        $grep->setInput($m[1]."\n");
        $grep->run();

        $this->assertSame(1, $grep->getExitCode(), "tracked files still carry it:\n".$grep->getOutput());
    }
}
