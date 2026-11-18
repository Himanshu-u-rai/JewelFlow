<?php

namespace Tests\Feature\FinancialYear;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\FinancialLockService;
use App\Services\InvoiceAccountingService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Settings → Billing → "Lock books" (owner, 2026-11-16): the only way to set shop_rules.financial_lock_date.
 * Constitution Art. VII.9: a period lock/unlock is always an explicit owner action, audited, never automatic.
 * "Today" in these tests is 2026-11-16 (IST), so the current financial year is 2026-27 and last year ended 2026-03-31.
 */
class BooksLockTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private User $owner;
    private Shop $shop;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        [$this->owner, $this->shop] = $this->createRetailerTenant();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'Asia/Kolkata'));
    }

    private function svc(): FinancialLockService
    {
        return new FinancialLockService();
    }

    private function lockDate(): ?string
    {
        $v = DB::table('shop_rules')->where('shop_id', $this->shop->id)->value('financial_lock_date');

        return $v ? substr((string) $v, 0, 10) : null;
    }

    private function audits(string $action)
    {
        return AuditLog::withoutGlobalScopes()->where('shop_id', $this->shop->id)->where('action', $action)->get();
    }

    private function staff(array $permissions = ['settings.view']): User
    {
        $role = new Role();
        $role->forceFill(['name' => 'manager', 'display_name' => 'Manager', 'shop_id' => $this->shop->id])->save();
        $user = User::factory()->create(['shop_id' => $this->shop->id, 'role_id' => $role->id, 'is_active' => true]);
        $this->grantOnlyPermissions($user, $permissions);

        return $user;
    }

    private function lockNow(string $date = '2026-03-31'): void
    {
        $this->svc()->lock($this->shop, $date, $this->owner, 'Closing last year');
    }

    // ---------------------------------------------------------------- service

    public function test_owner_locks_through_a_past_date(): void
    {
        $this->lockNow('2026-03-31');

        $this->assertSame('2026-03-31', $this->lockDate());
    }

    public function test_yesterday_is_the_latest_date_that_can_be_locked(): void
    {
        $this->lockNow('2026-11-15');
        $this->assertSame('2026-11-15', $this->lockDate());
    }

    public function test_today_cannot_be_locked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc()->lock($this->shop, '2026-11-16', $this->owner, 'x');
    }

    public function test_future_cannot_be_locked_and_nothing_is_stored(): void
    {
        try {
            $this->svc()->lock($this->shop, '2027-03-31', $this->owner, 'x');
            $this->fail('expected refusal');
        } catch (InvalidArgumentException) {
        }
        $this->assertNull($this->lockDate());
        $this->assertCount(0, $this->audits('books.locked'));
    }

    public function test_today_is_judged_in_india_not_utc(): void
    {
        // 00:30 IST on 17 Nov is still 16 Nov in UTC. In India "today" is the 17th, so the 16th is lockable and the 17th is not.
        $this->travelTo(CarbonImmutable::parse('2026-11-17 00:30:00', 'Asia/Kolkata'));
        $this->lockNow('2026-11-16');
        $this->assertSame('2026-11-16', $this->lockDate());
        $this->expectException(InvalidArgumentException::class);
        $this->svc()->lock($this->shop, '2026-11-17', $this->owner, 'x');
    }

    public function test_garbage_dates_are_refused(): void
    {
        foreach (['', 'tomorrow', '2026-13-40', '31/03/2026', '2026-02-30'] as $bad) {
            try {
                $this->svc()->lock($this->shop, $bad, $this->owner, 'x');
                $this->fail("accepted {$bad}");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertNull($this->lockDate());
    }

    public function test_locking_may_only_move_forward(): void
    {
        $this->lockNow('2026-03-31');
        $this->expectException(InvalidArgumentException::class);
        $this->svc()->lock($this->shop, '2026-03-30', $this->owner, 'going back');
    }

    public function test_locking_the_same_date_again_is_refused_not_silently_accepted(): void
    {
        $this->lockNow('2026-03-31');
        $this->expectException(InvalidArgumentException::class);
        $this->svc()->lock($this->shop, '2026-03-31', $this->owner, 'again');
    }

    public function test_locking_forward_extends_the_lock(): void
    {
        $this->lockNow('2026-03-31');
        $this->lockNow('2026-09-30');
        $this->assertSame('2026-09-30', $this->lockDate());
    }

    public function test_unlock_requires_a_reason_of_ten_characters(): void
    {
        $this->lockNow('2026-03-31');
        foreach (['', '   ', 'too short', "         x         "] as $reason) {
            try {
                $this->svc()->unlock($this->shop, null, $this->owner, $reason);
                $this->fail("accepted reason '{$reason}'");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame('2026-03-31', $this->lockDate());
        $this->assertCount(0, $this->audits('books.unlocked'));
    }

    public function test_unlock_fully_opens_the_books(): void
    {
        $this->lockNow('2026-03-31');
        $this->svc()->unlock($this->shop, null, $this->owner, 'Auditor found a wrong entry');
        $this->assertNull($this->lockDate());
    }

    public function test_unlock_can_move_the_date_back_with_a_reason(): void
    {
        $this->lockNow('2026-09-30');
        $this->svc()->unlock($this->shop, '2026-03-31', $this->owner, 'Reopen the second quarter');
        $this->assertSame('2026-03-31', $this->lockDate());
    }

    public function test_unlock_to_a_later_or_equal_date_is_refused(): void
    {
        $this->lockNow('2026-03-31');
        foreach (['2026-03-31', '2026-06-30'] as $d) {
            try {
                $this->svc()->unlock($this->shop, $d, $this->owner, 'This is not going back');
                $this->fail("accepted {$d}");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame('2026-03-31', $this->lockDate());
    }

    public function test_unlock_when_nothing_is_locked_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc()->unlock($this->shop, null, $this->owner, 'Nothing to unlock here');
    }

    public function test_last_financial_year_shortcut_locks_through_31_march(): void
    {
        $this->svc()->lockThroughLastFinancialYear($this->shop, $this->owner, 'Year closed');
        $this->assertSame('2026-03-31', $this->lockDate());
    }

    public function test_last_financial_year_shortcut_in_april_covers_the_year_just_ended(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-04-01 09:00:00', 'Asia/Kolkata'));
        $this->svc()->lockThroughLastFinancialYear($this->shop, $this->owner, 'Year closed');
        $this->assertSame('2027-03-31', $this->lockDate());
    }

    public function test_only_the_owner_may_lock_or_unlock_at_service_level(): void
    {
        $staff = $this->staff();
        try {
            $this->svc()->lock($this->shop, '2026-03-31', $staff, 'trying');
            $this->fail('lock allowed');
        } catch (AuthorizationException) {
        }
        $this->lockNow('2026-03-31');
        try {
            $this->svc()->unlock($this->shop, null, $staff, 'trying to open it');
            $this->fail('unlock allowed');
        } catch (AuthorizationException) {
        }
        $this->assertSame('2026-03-31', $this->lockDate());
    }

    public function test_another_shops_owner_cannot_lock_this_shop(): void
    {
        [$otherOwner] = $this->createRetailerTenant();
        $this->expectException(AuthorizationException::class);
        $this->svc()->lock($this->shop, '2026-03-31', $otherOwner, 'not mine');
    }

    public function test_lock_writes_an_audit_row_with_before_after_reason_and_user(): void
    {
        $this->lockNow('2026-03-31');
        $this->svc()->lock($this->shop, '2026-09-30', $this->owner, 'Half year done');

        $rows = $this->audits('books.locked');
        $this->assertCount(2, $rows);
        $last = $rows->sortBy('id')->last();
        $this->assertSame($this->owner->id, (int) $last->user_id);
        $this->assertSame(['financial_lock_date' => '2026-03-31'], $last->before);
        $this->assertSame(['financial_lock_date' => '2026-09-30'], $last->after);
        $this->assertSame('Half year done', $last->data['reason']);
    }

    public function test_unlock_writes_an_audit_row(): void
    {
        $this->lockNow('2026-03-31');
        $this->svc()->unlock($this->shop, null, $this->owner, 'Auditor found a wrong entry');

        $row = $this->audits('books.unlocked')->first();
        $this->assertNotNull($row);
        $this->assertSame(['financial_lock_date' => '2026-03-31'], $row->before);
        $this->assertSame(['financial_lock_date' => null], $row->after);
        $this->assertSame('Auditor found a wrong entry', $row->data['reason']);
        $this->assertSame($this->owner->id, (int) $row->user_id);
    }

    public function test_audit_rows_stay_append_only(): void
    {
        $this->lockNow('2026-03-31');
        $row = $this->audits('books.locked')->first();
        $this->expectException(LogicException::class);
        $row->update(['description' => 'tampered']);
    }

    public function test_a_refused_unlock_leaves_no_audit_row(): void
    {
        $this->lockNow('2026-03-31');
        try {
            $this->svc()->unlock($this->shop, null, $this->owner, 'short');
        } catch (InvalidArgumentException) {
        }
        $this->assertCount(0, $this->audits('books.unlocked'));
    }

    public function test_lock_works_when_the_shop_has_no_rules_row_yet(): void
    {
        DB::table('shop_rules')->where('shop_id', $this->shop->id)->delete();
        $this->lockNow('2026-03-31');
        $this->assertSame('2026-03-31', $this->lockDate());
    }

    public function test_a_stale_second_submit_cannot_move_the_lock_back_or_double_audit(): void
    {
        // Double-submit: both requests carry the same date. The second sees the first one's committed lock under the
        // row lock and is refused instead of writing a second audit row.
        $this->lockNow('2026-03-31');
        try {
            $this->svc()->lock($this->shop, '2026-03-31', $this->owner, 'Closing last year');
        } catch (InvalidArgumentException) {
        }
        $this->assertCount(1, $this->audits('books.locked'));
        $this->assertSame('2026-03-31', $this->lockDate());
    }

    public function test_service_reads_the_current_date_under_a_row_lock(): void
    {
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = strtolower($q->sql);
        });
        $this->lockNow('2026-03-31');
        $this->assertTrue(
            (bool) array_filter($sql, fn ($s) => str_contains($s, 'shop_rules') && str_contains($s, 'for update')),
            'shop_rules must be read with FOR UPDATE inside the lock transaction'
        );
    }

    public function test_lock_blocks_backdated_accounting_but_not_later_dates(): void
    {
        $this->lockNow('2026-03-31');

        foreach (['2026-03-31', '2026-01-15'] as $d) {
            try {
                InvoiceAccountingService::assertShopLockForDate($this->shop->id, $d);
                $this->fail("{$d} not blocked");
            } catch (LogicException $e) {
                $this->assertStringContainsString('2026-03-31', $e->getMessage());
            }
        }
        InvoiceAccountingService::assertShopLockForDate($this->shop->id, '2026-04-01');
        $this->addToAssertionCount(1);
    }

    public function test_unlock_reopens_the_period_for_accounting(): void
    {
        $this->lockNow('2026-03-31');
        $this->svc()->unlock($this->shop, null, $this->owner, 'Correcting a March entry');
        InvoiceAccountingService::assertShopLockForDate($this->shop->id, '2026-03-01');
        $this->addToAssertionCount(1);
    }

    // ------------------------------------------------------------------- HTTP

    private function submit(User $as, string $route, array $data)
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->actingAs($as)->post(route($route), $data));
    }

    public function test_owner_locks_through_http_with_the_confirm_box(): void
    {
        $this->submit($this->owner, 'settings.books.lock', ['through_date' => '2026-03-31', 'reason' => 'Year closed', 'confirm' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('2026-03-31', $this->lockDate());
    }

    public function test_lock_without_the_confirm_box_does_nothing(): void
    {
        $this->submit($this->owner, 'settings.books.lock', ['through_date' => '2026-03-31', 'reason' => 'x'])
            ->assertSessionHasErrors('confirm');

        $this->assertNull($this->lockDate());
    }

    public function test_http_lock_of_today_is_refused_with_a_message(): void
    {
        $this->submit($this->owner, 'settings.books.lock', ['through_date' => '2026-11-16', 'confirm' => '1'])
            ->assertSessionHasErrors('through_date');

        $this->assertNull($this->lockDate());
    }

    public function test_shortcut_locks_last_financial_year_through_http(): void
    {
        $this->submit($this->owner, 'settings.books.lock', ['shortcut' => 'last_year', 'confirm' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-03-31', $this->lockDate());
    }

    public function test_http_unlock_needs_reason_and_confirm(): void
    {
        $this->lockNow('2026-03-31');

        $this->submit($this->owner, 'settings.books.unlock', ['confirm' => '1', 'reason' => 'short'])
            ->assertSessionHasErrors('reason');
        $this->submit($this->owner, 'settings.books.unlock', ['reason' => 'A proper long reason'])
            ->assertSessionHasErrors('confirm');
        $this->assertSame('2026-03-31', $this->lockDate());

        $this->submit($this->owner, 'settings.books.unlock', ['confirm' => '1', 'reason' => 'A proper long reason'])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->lockDate());
    }

    public function test_http_unlock_can_set_an_earlier_date(): void
    {
        $this->lockNow('2026-09-30');
        $this->submit($this->owner, 'settings.books.unlock', ['confirm' => '1', 'reason' => 'Reopen second quarter', 'through_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-06-30', $this->lockDate());
    }

    public function test_non_owner_gets_403_on_both_routes_even_with_every_permission(): void
    {
        $manager = $this->staff(\App\Models\Permission::query()->pluck('name')->all());
        $this->lockNow('2026-03-31');

        $this->submit($manager, 'settings.books.lock', ['through_date' => '2026-09-30', 'confirm' => '1'])->assertForbidden();
        $this->submit($manager, 'settings.books.unlock', ['confirm' => '1', 'reason' => 'A proper long reason'])->assertForbidden();

        $this->assertSame('2026-03-31', $this->lockDate());
        $this->assertCount(1, $this->audits('books.locked'));
        $this->assertCount(0, $this->audits('books.unlocked'));
    }

    public function test_guest_cannot_post(): void
    {
        $this->post(route('settings.books.lock'), ['through_date' => '2026-03-31', 'confirm' => '1'])->assertRedirect();
        $this->assertNull($this->lockDate());
    }

    private function billingPage(User $as)
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->actingAs($as)->get(route('settings.edit', ['tab' => 'billing'])));
    }

    public function test_owner_sees_the_forms_with_turbo_top_and_the_explanation(): void
    {
        $this->lockNow('2026-03-31');
        $html = $this->billingPage($this->owner)->assertOk()->getContent();

        $this->assertStringContainsString('Lock books', $html);
        $this->assertStringContainsString('Nothing dated on or before this date can be added, edited or cancelled', $html);
        $this->assertStringContainsString('31 Mar 2026', $html);

        foreach (['settings.books.lock', 'settings.books.unlock'] as $r) {
            $this->assertMatchesRegularExpression(
                '/<form[^>]*action="[^"]*' . preg_quote(parse_url(route($r), PHP_URL_PATH), '/') . '"[^>]*data-turbo-frame="_top"/s',
                $html,
                "{$r} form must carry data-turbo-frame=\"_top\""
            );
        }
    }

    public function test_open_books_say_so(): void
    {
        $this->billingPage($this->owner)->assertOk()->assertSee('Books are open');
    }

    public function test_non_owner_sees_the_state_but_no_forms_and_no_reason(): void
    {
        $this->lockNow('2026-03-31');
        $this->svc()->lock($this->shop, '2026-09-30', $this->owner, 'SECRET-REASON-TEXT');
        $manager = $this->staff(['settings.view']);

        $html = $this->billingPage($manager)->assertOk()->getContent();

        $this->assertStringContainsString('30 Sep 2026', $html);
        $this->assertStringNotContainsString('SECRET-REASON-TEXT', $html);
        $this->assertStringNotContainsString(route('settings.books.lock'), $html);
        $this->assertStringNotContainsString(route('settings.books.unlock'), $html);
    }
}
