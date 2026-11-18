<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\PlatformAdmin;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The platform admin's email- and mobile-change codes allow six guesses. The cap was checked first and a wrong
 * guess counted only after its code was checked, so guesses sent at the same moment all passed the check before
 * any of them was counted: the cap held only for one guess at a time. Each guess now takes its slot before its
 * code is checked, and is refused once the six slots are gone.
 */
class PlatformAdminContactVerifyAttemptsTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const RIGHT = '123456';

    private const CHANNELS = [
        'email' => [
            'pending' => ['pending_email' => 'new@example.com'],
            'otp_key' => 'admin-email-change-otp:',
            'url' => '/admin/account/email/verify',
            'failed' => 'platform_admin.email_change_failed',
            'column' => 'email',
            'new' => 'new@example.com',
        ],
        'mobile' => [
            'pending' => ['pending_mobile' => '9111111111'],
            'otp_key' => 'admin-mobile-change-otp:',
            'url' => '/admin/account/mobile/verify',
            'failed' => 'platform_admin.mobile_change_failed',
            'column' => 'mobile_number',
            'new' => '9111111111',
        ],
    ];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Mail::fake();
    }

    private function pendingChange(string $channel): PlatformAdmin
    {
        $c = self::CHANNELS[$channel];
        $admin = PlatformAdmin::create(array_merge([
            'first_name' => 'Acc', 'last_name' => 'Admin', 'name' => 'Acc Admin',
            'email' => 'acc@example.com', 'mobile_number' => '9000000001',
            'password' => Hash::make('Sup3r-Secret!1'),
            'role' => 'super_admin', 'is_active' => true,
            'email_verified_at' => now(), 'password_changed_at' => now()->subDay(),
        ], $c['pending']));
        Cache::put($c['otp_key'] . $admin->id, Hash::make(self::RIGHT), now()->addMinutes(10));

        return $admin;
    }

    private function guess(PlatformAdmin $admin, string $channel, string $otp)
    {
        return $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->post(self::CHANNELS[$channel]['url'], ['otp' => $otp]);
    }

    private function checkedGuesses(PlatformAdmin $admin, string $channel): int
    {
        return DB::table('platform_audit_logs')
            ->where('action', self::CHANNELS[$channel]['failed'])
            ->where('target_id', $admin->id)
            ->count();
    }

    /**
     * Seven guesses arrive at the same moment: each next one is sent while the one before it is between the cap
     * and the check of its code (it is reading the stored code). Six wrong guesses, then the right one.
     */
    private function sendTogether(PlatformAdmin $admin, string $channel, array $guesses): void
    {
        $otpKey = self::CHANNELS[$channel]['otp_key'] . $admin->id;
        Event::listen(CacheHit::class, function (CacheHit $hit) use ($admin, $channel, $otpKey, &$guesses) {
            if ($hit->key === $otpKey && $guesses !== []) {
                $this->guess($admin, $channel, array_shift($guesses));
            }
        });

        $this->guess($admin, $channel, array_shift($guesses));
    }

    private function assertSevenTogetherCheckOnlySix(string $channel): void
    {
        $admin = $this->pendingChange($channel);
        $c = self::CHANNELS[$channel];

        $this->sendTogether($admin, $channel, ['000001', '000002', '000003', '000004', '000005', '000006', self::RIGHT]);

        $this->assertNotSame($c['new'], $admin->fresh()->{$c['column']}, 'a seventh guess is never checked');
        $this->assertSame(6, $this->checkedGuesses($admin, $channel));
    }

    public function test_email_code_guesses_sent_together_check_only_six(): void
    {
        $this->assertSevenTogetherCheckOnlySix('email');
    }

    public function test_mobile_code_guesses_sent_together_check_only_six(): void
    {
        $this->assertSevenTogetherCheckOnlySix('mobile');
    }

    /**
     * Counting first is not enough unless the count itself is never lost, and real processes lost counts twice:
     * RateLimiter::hit() on the database store (when its add() loses a race it puts 1 back over a parallel
     * increment), and the file store (CACHE_STORE=file in .env.example and the release rehearsals), whose increment()
     * reads the file and writes it back: 7-11 of 30 checked. The count is now taken under a cache lock, so it holds on
     * any store. Here the store reads, a parallel guess lands, then the store writes.
     */
    private function assertCountHoldsOnAStoreThatReadsThenWrites(string $channel): void
    {
        $store = new class extends \Illuminate\Cache\ArrayStore {
            public ?\Closure $between = null;

            public function increment($key, $value = 1)
            {
                $count = (int) $this->get($key);
                if ($this->between) {
                    [$hook, $this->between] = [$this->between, null];
                    $hook($key);
                }
                $this->storage[$key]['value'] = $count + $value;

                return $count + $value;
            }
        };
        Cache::extend('reads-then-writes', fn ($app) => Cache::repository($store));
        config(['cache.stores.reads-then-writes' => ['driver' => 'reads-then-writes'], 'cache.default' => 'reads-then-writes']);

        $admin = $this->pendingChange($channel);
        $c = self::CHANNELS[$channel];
        $store->between = fn () => $this->guess($admin, $channel, '000002');   // lands while 000001 is counted

        $this->guess($admin, $channel, '000001');
        foreach (['000003', '000004', '000005', '000006', '000007'] as $wrong) {
            $this->guess($admin, $channel, $wrong);
        }
        $this->guess($admin, $channel, self::RIGHT);

        $this->assertNotSame($c['new'], $admin->fresh()->{$c['column']});
        $this->assertSame(6, $this->checkedGuesses($admin, $channel), 'never more than six codes checked');
    }

    public function test_email_code_count_holds_on_a_store_that_reads_then_writes(): void
    {
        $this->assertCountHoldsOnAStoreThatReadsThenWrites('email');
    }

    public function test_mobile_code_count_holds_on_a_store_that_reads_then_writes(): void
    {
        $this->assertCountHoldsOnAStoreThatReadsThenWrites('mobile');
    }

    private function assertSixGuessesOneAtATime(string $channel, int $wrong, bool $rightApplies): void
    {
        $admin = $this->pendingChange($channel);
        $c = self::CHANNELS[$channel];
        for ($i = 1; $i <= $wrong; $i++) {
            $this->guess($admin, $channel, sprintf('%06d', $i))
                ->assertSessionHasErrors(['otp' => 'That code is invalid or has expired.']);
        }

        $response = $this->guess($admin, $channel, self::RIGHT);

        if ($rightApplies) {
            $response->assertSessionHas('status');
            $this->assertSame($c['new'], $admin->fresh()->{$c['column']});
        } else {
            $response->assertSessionHasErrors(['otp' => 'Too many attempts. Try again later.']);
            $this->assertNotSame($c['new'], $admin->fresh()->{$c['column']});
        }
        $this->assertSame($wrong, $this->checkedGuesses($admin, $channel));
    }

    public function test_email_code_right_on_the_sixth_guess_applies(): void
    {
        $this->assertSixGuessesOneAtATime('email', 5, true);
    }

    public function test_email_code_right_on_the_seventh_guess_is_refused(): void
    {
        $this->assertSixGuessesOneAtATime('email', 6, false);
    }

    public function test_mobile_code_right_on_the_sixth_guess_applies(): void
    {
        $this->assertSixGuessesOneAtATime('mobile', 5, true);
    }

    public function test_mobile_code_right_on_the_seventh_guess_is_refused(): void
    {
        $this->assertSixGuessesOneAtATime('mobile', 6, false);
    }
}
