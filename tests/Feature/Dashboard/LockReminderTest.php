<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Models\User;
use App\Services\FinancialLockService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\FinancialYear\SetsPeriodLock;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Owner lock reminder on the dashboard. It only links to the lock screen; it never locks. Dates are India's.
 */
class LockReminderTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase, SetsPeriodLock;

    private const ERP = 'https://jewelflows.com';

    private $user;

    private int $shopId;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
        [$this->user, $shop] = $this->createRetailerTenant();
        $this->shopId = (int) $shop->id;
    }

    private function at(string $when): void
    {
        $this->travelTo(Carbon::parse($when, 'Asia/Kolkata'));
    }

    private function dash(?User $who = null)
    {
        return TenantContext::runFor($this->shopId, fn () => $this->actingAs($who ?? $this->user)->get(self::ERP.'/dashboard'));
    }

    private function lockAt(?string $date): void
    {
        $this->setLock($this->shopId, $date);
        if ($date === null) {
            DB::table('shop_rules')->where('shop_id', $this->shopId)->update(['financial_lock_date' => null]);
        }
    }

    private function staff(): User
    {
        return TenantContext::runFor($this->shopId, function () {
            $role = (new Role())->forceFill(['name' => 'clerk-r', 'display_name' => 'Clerk', 'shop_id' => $this->shopId]);
            $role->save();

            return User::create(['name' => 'Clerk', 'mobile_number' => '9'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
                'password' => Hash::make('password'), 'realm' => 'erp', 'is_active' => true, 'shop_id' => $this->shopId, 'role_id' => $role->id]);
        });
    }

    public function test_year_end_banner_shows_for_the_owner_when_last_year_is_not_locked(): void
    {
        $this->at('2026-11-05 10:00');
        $this->lockAt('2026-01-31');

        $this->dash()->assertOk()
            ->assertSee('Financial year 2025-26 has ended. Lock its books so they can&#039;t change?', false)
            ->assertSee('data-lock-reminder="year"', false)
            ->assertSee(route('settings.edit', ['tab' => 'billing']).'#lock-books', false);
    }

    public function test_banner_shows_when_nothing_has_ever_been_locked(): void
    {
        $this->at('2026-11-05 10:00');
        $this->lockAt(null);

        $this->dash()->assertSee('data-lock-reminder="year"', false);
    }

    public function test_banner_is_hidden_once_the_lock_covers_last_year_and_the_month_is_early(): void
    {
        $this->at('2026-11-05 10:00');
        $this->lockAt('2026-03-31');

        $this->dash()->assertOk()->assertDontSee('data-lock-reminder', false);
    }

    public function test_banner_is_hidden_for_staff_with_no_explanation(): void
    {
        $this->at('2026-11-25 10:00');
        $this->lockAt(null);

        $this->dash($this->staff())->assertOk()
            ->assertDontSee('data-lock-reminder', false)
            ->assertDontSee('Lock its books', false)
            ->assertDontSee('Lock books through', false);
    }

    public function test_monthly_banner_shows_from_the_21st_and_names_last_months_end(): void
    {
        $this->at('2026-11-21 09:00');
        $this->lockAt('2026-09-30');

        $this->dash()->assertSee('data-lock-reminder="month"', false)->assertSee('Lock books through 31 October 2026?', false);
    }

    public function test_monthly_banner_waits_until_the_21st_and_hides_when_covered(): void
    {
        $this->at('2026-11-20 23:00');
        $this->lockAt('2026-09-30');
        $this->dash()->assertDontSee('data-lock-reminder', false);

        $this->at('2026-11-25 10:00');
        $this->lockAt('2026-10-31');
        $this->dash()->assertDontSee('data-lock-reminder', false);
    }

    public function test_year_end_banner_wins_over_the_monthly_one(): void
    {
        $this->at('2026-11-25 10:00');
        $this->lockAt('2026-01-31');

        $html = $this->dash()->getContent();
        $this->assertStringContainsString('data-lock-reminder="year"', $html);
        $this->assertStringNotContainsString('data-lock-reminder="month"', $html);
        $this->assertSame(1, substr_count($html, 'data-lock-reminder='));
    }

    public function test_not_now_hides_the_reminder_until_next_month_and_locks_nothing(): void
    {
        $this->at('2026-11-25 10:00');
        $this->lockAt('2026-09-30');
        $this->dash()->assertSee('data-lock-reminder', false);

        TenantContext::runFor($this->shopId, fn () => $this->actingAs($this->user)->post(route('dashboard.lock-reminder.dismiss')))
            ->assertRedirect(route('dashboard'));

        $this->dash()->assertDontSee('data-lock-reminder', false);
        $this->assertSame('2026-09-30', FinancialLockService::currentLockDate($this->shopId));

        $this->at('2026-12-22 10:00'); // next month, still due
        $this->dash()->assertSee('data-lock-reminder="month"', false);
    }

    public function test_staff_cannot_dismiss_the_owners_reminder(): void
    {
        $this->at('2026-11-25 10:00');
        $this->lockAt('2026-09-30');

        TenantContext::runFor($this->shopId, fn () => $this->actingAs($this->staff())->post(route('dashboard.lock-reminder.dismiss')))->assertForbidden();

        $this->dash()->assertSee('data-lock-reminder', false);
    }

    public function test_dates_follow_india_time(): void
    {
        // 31 Oct 2026 18:45 UTC is 1 Nov 00:15 IST: the month has turned, the 21st is not reached: no monthly banner.
        $this->travelTo(Carbon::parse('2026-10-31 18:45:00', 'UTC'));
        $this->lockAt('2026-03-31');
        $this->dash()->assertDontSee('data-lock-reminder', false);

        // 20 Nov 2026 19:00 UTC is 21 Nov 00:30 IST: the monthly banner is due.
        $this->travelTo(Carbon::parse('2026-11-20 19:00:00', 'UTC'));
        $this->lockAt('2026-09-30');
        $this->dash()->assertSee('data-lock-reminder="month"', false);

        // 31 Mar 2027 19:00 UTC is 1 Apr 2027 00:30 IST: 2026-27 has ended.
        $this->travelTo(Carbon::parse('2027-03-31 19:00:00', 'UTC'));
        $this->lockAt('2026-03-31');
        $this->dash()->assertSee('Financial year 2026-27 has ended', false);
    }

    public function test_rendering_the_reminder_never_changes_the_lock(): void
    {
        $this->at('2026-04-02 10:00');
        $this->lockAt(null);
        $this->dash()->assertSee('data-lock-reminder', false);

        $this->assertNull(FinancialLockService::currentLockDate($this->shopId));
        $this->assertSame(0, \App\Models\AuditLog::withoutGlobalScopes()->where('shop_id', $this->shopId)->whereIn('action', ['books.locked', 'books.unlocked'])->count());
    }
}
