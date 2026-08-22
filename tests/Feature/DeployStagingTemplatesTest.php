<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the committed STAGING deployment artifacts (systemd worker unit + the
 * dedicated reconciliation cron) against the exact mistakes this task corrected:
 *   • an unsupported `--stop-timeout` flag on queue:work,
 *   • a worker that drains the `default` queue instead of the isolated one,
 *   • a cron that runs the whole scheduler instead of the one bounded command.
 *
 * These are file-content assertions — no DB — so they run everywhere.
 */
class DeployStagingTemplatesTest extends TestCase
{
    private function unit(): string
    {
        return file_get_contents(base_path('deploy/staging/jewelflow-staging-ops-alerts.service'));
    }

    private function cron(): string
    {
        return file_get_contents(base_path('deploy/staging/jewelflow-staging-reconcile.cron'));
    }

    /** The single ExecStart directive line (comments may legitimately mention flags). */
    private function execStart(): string
    {
        foreach (explode("\n", $this->unit()) as $line) {
            if (str_starts_with(trim($line), 'ExecStart=')) {
                return $line;
            }
        }
        $this->fail('no ExecStart line in the unit');
    }

    /** The single non-comment cron command line. */
    private function cronCommand(): string
    {
        foreach (explode("\n", $this->cron()) as $line) {
            $t = trim($line);
            if ($t !== '' && ! str_starts_with($t, '#')) {
                return $t;
            }
        }
        $this->fail('no command line in the cron');
    }

    // ── Proof 1: no invalid --stop-timeout; --timeout=60 present ─────────────

    public function test_worker_uses_supported_timeout_not_stop_timeout(): void
    {
        $exec = $this->execStart();
        $this->assertStringNotContainsString('--stop-timeout', $exec,
            'queue:work has no --stop-timeout in this Laravel version');
        $this->assertStringContainsString('--timeout=60', $exec,
            'must set the supported per-job --timeout');
        $this->assertStringContainsString('--max-time=3600', $exec);
    }

    // ── Proof 2: systemd unit is structurally valid ─────────────────────────

    public function test_systemd_unit_has_required_directives(): void
    {
        $unit = $this->unit();
        foreach ([
            '[Unit]', '[Service]', '[Install]',
            'User=www-data', 'Group=www-data',
            'WorkingDirectory=/var/www/jewelflow-staging',
            'ExecStart=/usr/bin/php /var/www/jewelflow-staging/artisan queue:work',
            'Restart=always',
            'KillSignal=SIGTERM',      // graceful shutdown replaces --stop-timeout
            'TimeoutStopSec=90',       // bounded drain of the in-flight job
            'SyslogIdentifier=jewelflow-staging-ops-alerts',
            'WantedBy=multi-user.target',
        ] as $needle) {
            $this->assertStringContainsString($needle, $unit, "unit missing: {$needle}");
        }
    }

    // ── Proof 3: worker drains ops-alerts, never the default queue ──────────

    public function test_worker_consumes_only_the_isolated_ops_alerts_queue(): void
    {
        $exec = $this->execStart();
        $this->assertStringContainsString('queue:work database --queue=ops-alerts', $exec,
            'must pin to the database/ops-alerts lane');
        $this->assertStringNotContainsString('--queue=default', $exec);
        // No bare `queue:work database --sleep…` (that would default to `default`).
        $this->assertDoesNotMatchRegularExpression('/queue:work\s+database\s+--sleep/', $exec,
            'queue must be named before other flags');
    }

    // ── Cron: dedicated bounded reconcile, not the full scheduler ───────────

    public function test_reconcile_cron_is_dedicated_bounded_and_locked(): void
    {
        $cmd = $this->cronCommand();
        $this->assertStringNotContainsString('schedule:run', $cmd,
            'must NOT drive the whole Laravel scheduler on staging');
        $this->assertStringContainsString('/usr/bin/flock -n', $cmd,
            'non-blocking flock gives overlap protection');
        $this->assertStringContainsString('/usr/bin/php /var/www/jewelflow-staging/artisan subscription:reconcile-payments', $cmd);
        $this->assertStringContainsString('--limit=25', $cmd);
        $this->assertStringContainsString('--days=2', $cmd);
        $this->assertStringContainsString('--sleep-ms=200', $cmd);
        $this->assertStringContainsString('storage/framework/reconcile-payments.lock', $cmd,
            'lock file lives under staging storage, never /etc');
        $this->assertStringContainsString('>/dev/null 2>&1', $cmd,
            'routine output discarded so the cron log cannot grow unbounded');
        $this->assertStringNotContainsString('/var/www/jewelflow-production', $cmd,
            'no production path in a staging template');
    }

    // ── Runbook: no runnable command may point at the production install ────

    /**
     * Every executable line in STAGING_DEPLOY.md, comments stripped.
     *
     * Prose and `#` comments are allowed to name the production path (the doc
     * has to say which directory to avoid); a line you can paste into a shell
     * is not.
     *
     * @return list<array{int, string}> [line number, code]
     */
    private function runbookCommands(): array
    {
        $lines = explode("\n", file_get_contents(base_path('STAGING_DEPLOY.md')));
        $inFence = false;
        $commands = [];

        foreach ($lines as $i => $line) {
            if (str_starts_with(trim($line), '```')) {
                $inFence = ! $inFence;
                continue;
            }
            if (! $inFence) {
                continue;
            }
            $code = trim(preg_replace('/#.*$/', '', $line));
            if ($code !== '') {
                $commands[] = [$i + 1, $code];
            }
        }

        $this->assertNotEmpty($commands, 'no fenced commands found — did the runbook move?');

        return $commands;
    }

    public function test_staging_runbook_never_targets_the_production_directory(): void
    {
        foreach ($this->runbookCommands() as [$lineNo, $code]) {
            $this->assertDoesNotMatchRegularExpression(
                '#/var/www/jewelflow(?!-staging)\b#',
                $code,
                "STAGING_DEPLOY.md:{$lineNo} runs against the PRODUCTION path: {$code}",
            );
        }
    }

    public function test_staging_runbook_refuses_to_run_against_a_production_env(): void
    {
        $doc = file_get_contents(base_path('STAGING_DEPLOY.md'));

        $this->assertStringContainsString('export APP_DIR=/var/www/jewelflow-staging', $doc,
            'the runbook must set APP_DIR to staging up front');
        $this->assertStringContainsString("grep -qE '^APP_ENV=(production|prod)\$' \"\$APP_DIR/.env\"", $doc,
            'the runbook must hard-stop on a production .env, not just warn in prose');
    }

    // ── /etc/cron.d grammar: 5 timing fields + user field + command ─────────

    public function test_reconcile_cron_uses_full_etc_crond_grammar_with_user(): void
    {
        $cmd = $this->cronCommand();

        // /etc/cron.d lines carry a mandatory user field AFTER the five timing
        // fields. Without it the line is a syntax error and silently never runs.
        // Grammar: <min> <hour> <dom> <mon> <dow> <user> <command...>
        $this->assertMatchesRegularExpression(
            '#^\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+www-data\s+/usr/bin/flock\b#',
            $cmd,
            'cron.d line must be: 5 timing fields, then www-data, then the flock command',
        );

        // Exactly five whitespace-separated timing tokens precede the user field.
        [$schedule] = explode('www-data', $cmd, 2);
        $this->assertCount(5, preg_split('/\s+/', trim($schedule)),
            'exactly five cron timing fields before the user');

        // File must end with a trailing newline (cron ignores a final unterminated line).
        $this->assertStringEndsWith("\n", $this->cron(), 'cron file needs a final newline');
    }

    // ── the asset build must be verified, not just run ──────────────────────

    /**
     * `npm run build` cannot fail on a stale bundle; `npm run build:verify` can.
     *
     * assets:verify-fresh exists precisely to catch a Vite build that produced
     * nothing while new Blade shipped — its own failure message says "Run npm
     * run build:verify before deploying". The runbook told the operator to run
     * plain `npm run build`, so that check was never on the deploy path.
     */
    public function test_the_runbook_builds_assets_with_the_freshness_check(): void
    {
        foreach ($this->runbookCommands() as [$lineNo, $code]) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bnpm run build\b(?!:verify)/',
                $code,
                "STAGING_DEPLOY.md:{$lineNo} builds assets without the staleness check "
                . "— use `npm run build:verify`: {$code}",
            );
        }
    }

    /** The script the runbook now depends on has to actually exist. */
    public function test_build_verify_is_a_real_npm_script_that_runs_the_check(): void
    {
        $package = json_decode(file_get_contents(base_path('package.json')), true);

        $this->assertArrayHasKey('build:verify', $package['scripts'] ?? [],
            'the runbook calls npm run build:verify');
        $this->assertStringContainsString('assets:verify-fresh', $package['scripts']['build:verify'],
            'build:verify must run the freshness check, not just vite build');
    }
}
