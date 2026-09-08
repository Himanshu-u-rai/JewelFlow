<?php

namespace Tests\Feature\Admin;

use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Services\PlatformAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Guards shop:withdraw-admin-restriction — the correction for an administrative
 * restriction that should never have been applied.
 *
 * The command has one job and one column: it clears shops.suspended_by so the
 * shop returns to the entitlement axis, and it must NOT widen access doing so.
 *
 * Everything else here is a REFUSAL or an ATOMICITY test. This command writes to
 * production data and its guards are the whole safety argument, so each one is
 * pinned individually — a guard that silently stops firing is indistinguishable
 * from a guard that was never there. In particular every `--expect-*` assertion
 * has a test proving the command REFUSES when it is omitted, because an optional
 * safeguard protects nothing.
 */
class WithdrawAdminRestrictionCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();

        // The command refuses outright unless enforcement is on; production runs
        // with PLATFORM_ENFORCE_SUBSCRIPTIONS=true and the refusal has its own
        // test below.
        config()->set('platform.enforce_subscriptions', true);
    }

    private function makeAdmin(string $role = 'super_admin', bool $active = true): PlatformAdmin
    {
        return PlatformAdmin::create([
            'first_name' => 'Withdraw', 'last_name' => 'Test', 'name' => 'Withdraw Test',
            'email' => 'withdraw' . random_int(1000, 999999) . '@example.com',
            'mobile_number' => '9' . random_int(100000000, 999999999),
            'password' => Hash::make('password'),
            'role' => $role, 'is_active' => $active,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * THE PRODUCTION SHAPE. A shop suspended with an administrator stamp, whose
     * subscription is a legacy `read_only` row whose term has ended, carrying the
     * legacy "Subscription read_only" reason the old pre-filled admin form left
     * behind. The reason already corroborates a lapse — suspended_by is the ONLY
     * thing making this an administrative hold.
     */
    private function heldShop(
        ?PlatformAdmin $holder = null,
        string $reason = 'Subscription read_only',
        bool $withHistory = true
    ): array {
        $holder ??= $this->makeAdmin();
        $plan = $this->createPlan('retailer');
        $shop = $this->createShop('retailer');

        ShopSubscription::create([
            'shop_id'   => $shop->id,
            'plan_id'   => $plan->id,
            'status'    => 'read_only',
            'starts_at' => now()->subMonths(14)->toDateString(),
            'ends_at'   => now()->subDays(30)->toDateString(),
        ]);

        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subHours(3),
            'suspension_reason' => $reason,
            'suspended_by'      => $holder->id,
        ])->save();

        $event = $withHistory ? $this->recordSuspendEvent($shop->fresh(), $holder, $reason) : null;

        return [$shop->fresh(), $holder, $event];
    }

    /**
     * Mirrors the audit row ShopManagementController::updateStatus() writes,
     * including its flat (non-nested) shape and — importantly — the fact that its
     * before/after are `$shop->only([...])` over ALL SIX access columns. The
     * after-state is therefore always read back off the real row, exactly as
     * production does it, so a fixture can never record a state the row does not
     * actually hold.
     */
    private function recordAccessEvent(
        Shop $shop,
        PlatformAdmin $actor,
        string $action,
        array $before,
        ?string $reason = null
    ): PlatformAuditLog {
        $shop = $shop->fresh();

        return PlatformAuditLog::create([
            'actor_admin_id' => $actor->id,
            'action'         => $action,
            'target_type'    => Shop::class,
            'target_id'      => $shop->id,
            'before'         => $before,
            'after'          => $shop->only([
                'is_active', 'access_mode', 'suspended_at',
                'suspended_by', 'suspension_reason', 'suspended_until',
            ]),
            'reason'         => $reason,
            'created_at'     => now(),
        ]);
    }

    private function recordSuspendEvent(
        Shop $shop,
        PlatformAdmin $actor,
        string $reason,
        ?string $beforeMode = 'read_only'
    ): PlatformAuditLog {
        return $this->recordAccessEvent($shop, $actor, 'shop.suspend', [
            'is_active' => false, 'access_mode' => $beforeMode,
            'suspended_at' => null, 'suspended_by' => null,
            'suspension_reason' => $reason, 'suspended_until' => null,
        ], $reason);
    }

    /** The raw shops row, read past Eloquent so no cast or mutator can hide a change. */
    private function rawRow(int $id): array
    {
        return (array) \Illuminate\Support\Facades\DB::table('shops')->where('id', $id)->first();
    }

    private function latestSubscription(Shop $shop): ?ShopSubscription
    {
        return ShopSubscription::where('shop_id', $shop->id)->latest('id')->first();
    }

    /** Every option the command demands before it will write. */
    private function commitArgs(Shop $shop, PlatformAdmin $holder, PlatformAdmin $authorizer, ?int $eventId): array
    {
        return [
            'shop'                  => $shop->id,
            '--authorized-by'       => $authorizer->id,
            '--reason'              => 'Applied in error while attempting to clear a read-only state',
            '--expect-suspended-by' => $holder->id,
            '--expect-updated-at'   => $shop->updated_at->format('Y-m-d H:i:s'),
            '--expect-audit-event'  => $eventId,
            '--commit'              => true,
        ];
    }

    // ════════════════════════════════════════════════════════════════
    // The correction itself
    // ════════════════════════════════════════════════════════════════

    public function test_it_withdraws_the_attribution_and_restores_the_owners_purchase_path(): void
    {
        [$shop, $holder, $event] = $this->heldShop();
        $authorizer = $this->makeAdmin();

        // Preconditions, asserted rather than assumed: without these the success
        // assertions could pass on a shop that was never blocked.
        $this->assertTrue(ShopSubscription::blocksNewPaidTerm($this->latestSubscription($shop), $shop));
        $this->assertSame('admin_suspended', $shop->accessClassification());

        $this->artisan('shop:withdraw-admin-restriction', $this->commitArgs($shop, $holder, $authorizer, $event->id))
            ->assertExitCode(0);

        $shop = $shop->fresh();

        $this->assertNull($shop->suspended_by);
        $this->assertFalse(ShopSubscription::blocksNewPaidTerm($this->latestSubscription($shop), $shop));
        $this->assertSame('subscription_lapse', $shop->accessClassification());
    }

    public function test_it_does_not_grant_any_access_the_subscription_has_not_paid_for(): void
    {
        [$shop, $holder, $event] = $this->heldShop();

        $this->artisan('shop:withdraw-admin-restriction', $this->commitArgs($shop, $holder, $this->makeAdmin(), $event->id))
            ->assertExitCode(0);

        $shop = $shop->fresh();

        $this->assertSame('suspended', $shop->access_mode);
        $this->assertFalse((bool) $shop->is_active);
        $this->assertFalse(ShopSubscription::entitlesAccessToday($shop));
        // The lapse is still on record; the term was not extended or altered.
        $this->assertSame('read_only', $this->latestSubscription($shop)->status);
        $this->assertTrue($this->latestSubscription($shop)->ends_at->isPast());
    }

    /**
     * The whole point of the correction, end to end: the owner regains the
     * plan-selection path while the ERP itself stays shut.
     */
    public function test_recovery_opens_the_plan_picker_while_erp_stays_blocked(): void
    {
        [$shop, $holder, $event] = $this->heldShop();
        $owner = $this->createOwnerUser($shop, $this->createOwnerRole($shop->id));

        // BEFORE: an administrative hold is a Contact-Support dead end. The plan
        // picker refuses and denyAdministrative() logs the owner out.
        $this->actingAs($owner)->get(route('subscription.plans'))->assertRedirect(route('login'));

        $this->artisan('shop:withdraw-admin-restriction', $this->commitArgs($shop, $holder, $this->makeAdmin(), $event->id))
            ->assertExitCode(0);

        // AFTER: the plan picker is reachable …
        $this->actingAs($owner->fresh())->get(route('subscription.plans'))->assertOk();

        // … and the ERP is still shut, routed to recovery rather than opened.
        $this->actingAs($owner->fresh())->get(route('dashboard'))
            ->assertRedirect(route('subscription.plans'));
    }

    // ════════════════════════════════════════════════════════════════
    // Audit attribution and atomicity
    // ════════════════════════════════════════════════════════════════

    public function test_the_audit_record_names_the_authorizer_and_preserves_the_withdrawn_administrator(): void
    {
        [$shop, $holder, $event] = $this->heldShop();
        $authorizer = $this->makeAdmin();

        $this->artisan('shop:withdraw-admin-restriction', $this->commitArgs($shop, $holder, $authorizer, $event->id))
            ->assertExitCode(0);

        $entry = PlatformAuditLog::query()
            ->where('action', 'shop.admin_restriction_withdrawn')
            ->where('target_type', Shop::class)
            ->where('target_id', $shop->id)
            ->latest('id')->first();

        $this->assertNotNull($entry, 'The correction must leave an audit record.');
        // The AUTHORIZER is the actor — not the lowest-id super_admin the service
        // would otherwise silently substitute, and not the administrator whose
        // restriction is being withdrawn.
        $this->assertSame($authorizer->id, $entry->actor_admin_id);
        $this->assertNotSame($holder->id, $entry->actor_admin_id);
        // The withdrawn administrator survives separately, in the before-state.
        $this->assertSame($holder->id, $entry->before['suspended_by']);
        $this->assertNull($entry->after['suspended_by']);
    }

    public function test_the_correction_is_rolled_back_when_the_audit_record_is_not_written(): void
    {
        [$shop, $holder, $event] = $this->heldShop();

        // PlatformAuditService::log() returns void and RETURNS SILENTLY when it
        // cannot resolve an actor — a successful call is not evidence of a write.
        // This stub reproduces exactly that failure mode.
        $this->app->instance(PlatformAuditService::class, new class extends PlatformAuditService {
            public function log(
                ?PlatformAdmin $actor, string $action, string $targetType, int|string|null $targetId,
                ?array $before = null, ?array $after = null, ?string $reason = null,
                ?\Illuminate\Http\Request $request = null
            ): void {
                // writes nothing
            }
        });

        $this->assertSame(1, Artisan::call(
            'shop:withdraw-admin-restriction',
            $this->commitArgs($shop, $holder, $this->makeAdmin(), $event->id)
        ));
        $output = Artisan::output();

        // An unrecorded correction must not survive …
        $this->assertSame($holder->id, $shop->fresh()->suspended_by);

        // … and must not be REPORTED as having survived. Success is printed only
        // after the transaction commits, so a rolled-back run must say nothing of
        // the sort; an operator reading "Committed." would stop checking.
        $this->assertStringNotContainsString('Committed.', $output);
        $this->assertStringNotContainsString('New state:', $output);
        $this->assertStringContainsString('audit record was not written', $output);
    }

    public function test_it_refuses_an_unnamed_unknown_inactive_or_non_super_admin_authorizer(): void
    {
        foreach ([null, 999999, $this->makeAdmin(active: false)->id, $this->makeAdmin('support')->id] as $authorizerId) {
            [$shop, $holder, $event] = $this->heldShop();

            $this->artisan('shop:withdraw-admin-restriction', [
                'shop'                  => $shop->id,
                '--authorized-by'       => $authorizerId,
                '--reason'              => 'Applied in error',
                '--expect-suspended-by' => $holder->id,
                '--expect-updated-at'   => $shop->updated_at->format('Y-m-d H:i:s'),
                '--expect-audit-event'  => $event->id,
                '--commit'              => true,
            ])->assertExitCode(1);

            $this->assertSame($holder->id, $shop->fresh()->suspended_by);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Mandatory assertions — an optional safeguard protects nothing
    // ════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\DataProvider('omittedSafeguards')]
    public function test_it_refuses_to_commit_when_a_required_assertion_is_omitted(string $omit): void
    {
        [$shop, $holder, $event] = $this->heldShop();
        $args = $this->commitArgs($shop, $holder, $this->makeAdmin(), $event->id);
        unset($args[$omit]);

        $this->artisan('shop:withdraw-admin-restriction', $args)->assertExitCode(1);

        $this->assertSame($holder->id, $shop->fresh()->suspended_by);
    }

    public static function omittedSafeguards(): array
    {
        return [
            'no authorizer'    => ['--authorized-by'],
            'no reason'        => ['--reason'],
            'no expected hold' => ['--expect-suspended-by'],
            'no expected time' => ['--expect-updated-at'],
            'no expected event'=> ['--expect-audit-event'],
        ];
    }

    public function test_it_refuses_when_a_different_administrator_now_holds_the_shop(): void
    {
        [$shop, $holder, $event] = $this->heldShop();
        $someoneElse = $this->makeAdmin();

        $args = $this->commitArgs($shop, $holder, $this->makeAdmin(), $event->id);
        $args['--expect-suspended-by'] = $someoneElse->id;

        $this->artisan('shop:withdraw-admin-restriction', $args)->assertExitCode(1);
        $this->assertSame($holder->id, $shop->fresh()->suspended_by);
    }

    public function test_it_refuses_when_the_shop_was_modified_after_it_was_inspected(): void
    {
        [$shop, $holder, $event] = $this->heldShop();

        $args = $this->commitArgs($shop, $holder, $this->makeAdmin(), $event->id);
        $args['--expect-updated-at'] = now()->subYear()->format('Y-m-d H:i:s');

        $this->artisan('shop:withdraw-admin-restriction', $args)->assertExitCode(1);
        $this->assertNotNull($shop->fresh()->suspended_by);
    }

    /**
     * THE CASE NOTHING ELSE CATCHES. A second hold by the SAME administrator
     * carrying the SAME reason leaves the shop's own columns byte-identical —
     * suspended_at is sticky (`?: now()`), so even the timestamps match. Only the
     * appended audit event distinguishes it, which is why the commit is bound to
     * the reviewed event id.
     */
    public function test_it_refuses_a_newer_identical_looking_hold_by_the_same_administrator(): void
    {
        [$shop, $holder, $reviewedEvent] = $this->heldShop();

        // A later, legitimate re-suspension. Same actor, same reason, same
        // resulting row — indistinguishable from the first in every column.
        $this->recordSuspendEvent($shop, $holder, 'Subscription read_only', 'suspended');

        $args = $this->commitArgs($shop, $holder, $this->makeAdmin(), $reviewedEvent->id);

        $this->artisan('shop:withdraw-admin-restriction', $args)->assertExitCode(1);
        $this->assertSame($holder->id, $shop->fresh()->suspended_by);
    }

    // ════════════════════════════════════════════════════════════════
    // Reconciliation — history supports attribution, absence proves nothing
    // ════════════════════════════════════════════════════════════════

    public function test_it_refuses_when_the_row_does_not_match_its_latest_audit_event(): void
    {
        [$shop, $holder, $event] = $this->heldShop();

        // updateStatus() saves the shop BEFORE writing its audit entry, with no
        // enclosing transaction, so a state change with no matching record is a
        // real production possibility. Simulate one: the row moved on, the log
        // did not.
        Shop::query()->whereKey($shop->id)->update(['access_mode' => 'read_only']);

        $args = $this->commitArgs($shop->fresh(), $holder, $this->makeAdmin(), $event->id);

        $this->artisan('shop:withdraw-admin-restriction', $args)->assertExitCode(1);
        $this->assertSame($holder->id, $shop->fresh()->suspended_by);
    }

    /**
     * THE GAP THE MODE-AND-ACTOR COMPARISON LEFT OPEN. An unlogged write can move
     * a restriction field that is neither the mode nor the administrator, and on
     * those two columns alone the row still reconciles perfectly.
     *
     * The reason case is deliberately 'Subscription expired' — it still starts
     * with "Subscription", so suspensionIsSubscriptionManaged() still corroborates
     * a lapse and the classification guard still passes. Nothing but a complete
     * six-column reconciliation catches it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unloggedFieldWrites')]
    public function test_it_refuses_when_a_restriction_field_other_than_mode_or_actor_disagrees(array $write): void
    {
        [$shop, $holder, $event] = $this->heldShop();

        // Straight to the row, past Eloquent and past every cast: this is a write
        // with no audit entry, which is exactly what updateStatus() can leave
        // behind when it fails between saving the shop and logging the change.
        \Illuminate\Support\Facades\DB::table('shops')->where('id', $shop->id)->update($write);
        $shop = $shop->fresh();

        // The two columns the old comparison looked at are untouched.
        $this->assertSame('suspended', $shop->access_mode);
        $this->assertSame($holder->id, $shop->suspended_by);

        $this->assertSame(1, Artisan::call(
            'shop:withdraw-admin-restriction',
            $this->commitArgs($shop, $holder, $this->makeAdmin(), $event->id)
        ));

        // It must be RECONCILIATION that refused — not a coincidental refusal
        // from one of the other guards, which would leave the gap open.
        $this->assertStringContainsString('does not match its latest audit event', Artisan::output());
        $this->assertSame($holder->id, $shop->fresh()->suspended_by);
    }

    public static function unloggedFieldWrites(): array
    {
        return [
            // Still "Subscription…", so the lapse corroboration cannot mask this.
            'reason rewritten'      => [['suspension_reason' => 'Subscription expired']],
            'suspension period set' => [['suspended_until' => '2027-01-31 00:00:00']],
            'suspended_at moved'    => [['suspended_at' => '2026-01-01 00:00:00']],
            // 'true' as a literal: this goes in through the raw query builder, and
            // Postgres will not coerce an integer 1 into a boolean column.
            'is_active flipped'     => [['is_active' => 'true']],
        ];
    }

    /**
     * THE INVISIBLE EVENT. An entry whose recorded fields are only the suspension
     * period narrowed to nothing once the dates were excluded, so it was filtered
     * out of the history entirely: it never reconciled, and — the real damage —
     * it never moved the latest event id, so a reviewed id stayed "current" right
     * across a later administrative change.
     *
     * With the dates included it is visible again, and the command refuses,
     * naming that event rather than the stale one the operator reviewed.
     */
    public function test_a_later_event_recording_only_the_suspension_period_is_not_invisible(): void
    {
        [$shop, $holder, $reviewedEvent] = $this->heldShop();

        \Illuminate\Support\Facades\DB::table('shops')
            ->where('id', $shop->id)
            ->update(['suspended_until' => '2027-01-31 00:00:00']);

        $periodOnly = PlatformAuditLog::create([
            'actor_admin_id' => $holder->id,
            'action'         => 'shop.suspend',
            'target_type'    => Shop::class,
            'target_id'      => $shop->id,
            'before'         => ['suspended_at' => $shop->suspended_at, 'suspended_until' => null],
            'after'          => ['suspended_at' => $shop->suspended_at, 'suspended_until' => '2027-01-31 00:00:00'],
            'reason'         => 'Hold extended',
            'created_at'     => now(),
        ]);

        $this->assertSame(1, Artisan::call(
            'shop:withdraw-admin-restriction',
            $this->commitArgs($shop->fresh(), $holder, $this->makeAdmin(), $reviewedEvent->id)
        ));
        $output = Artisan::output();

        // The period event is what the command now reads as latest. That it is
        // named at all is the proof it is no longer being skipped.
        $this->assertStringContainsString("(#{$periodOnly->id},", $output);
        $this->assertSame($holder->id, $shop->fresh()->suspended_by);
    }

    /**
     * The same class of change recorded in the full six-column shape production
     * actually writes: mode, administrator, reason and is_active all unchanged,
     * only the period moved. The row and the later event agree, so reconciliation
     * passes and the event BINDING is isolated as the thing that must refuse.
     */
    public function test_a_later_suspension_period_event_invalidates_the_reviewed_event_id(): void
    {
        [$shop, $holder, $reviewedEvent] = $this->heldShop();

        $before = $shop->only([
            'is_active', 'access_mode', 'suspended_at',
            'suspended_by', 'suspension_reason', 'suspended_until',
        ]);

        // A later administrative change to the suspension PERIOD only. Mode,
        // administrator, reason and is_active are all left exactly as they were.
        Shop::query()->whereKey($shop->id)->update(['suspended_until' => now()->addMonths(2)]);
        $periodEvent = $this->recordAccessEvent($shop, $holder, 'shop.suspend', $before, 'Hold extended');

        $this->assertGreaterThan($reviewedEvent->id, $periodEvent->id);

        $shop = $shop->fresh();

        // The reviewed id is stale, and the command must say so rather than
        // proceed on an inspection that predates a real administrative change.
        $args = $this->commitArgs($shop, $holder, $this->makeAdmin(), $reviewedEvent->id);
        $this->assertSame(1, Artisan::call('shop:withdraw-admin-restriction', $args));
        $this->assertStringContainsString('latest access-relevant audit event', Artisan::output());
        $this->assertSame($holder->id, $shop->fresh()->suspended_by);

        // …and it is the BINDING that refused, not a broken fixture: the same
        // command bound to the period event goes through.
        $args['--expect-audit-event'] = $periodEvent->id;
        $this->artisan('shop:withdraw-admin-restriction', $args)->assertExitCode(0);
        $this->assertNull($shop->fresh()->suspended_by);
    }

    /**
     * Incomplete evidence is refused, not partially checked. An entry recording
     * only some of the six access columns cannot establish the restriction state,
     * and reading it as if it had would be worse than having no entry at all —
     * the operator would be told the state reconciled.
     */
    public function test_it_refuses_when_the_latest_event_does_not_record_every_access_column(): void
    {
        [$shop, $holder] = $this->heldShop(withHistory: false);

        $partial = PlatformAuditLog::create([
            'actor_admin_id' => $holder->id,
            'action'         => 'shop.suspend',
            'target_type'    => Shop::class,
            'target_id'      => $shop->id,
            'before'         => ['access_mode' => 'read_only', 'suspended_by' => null],
            'after'          => ['access_mode' => 'suspended', 'suspended_by' => $holder->id],
            'created_at'     => now(),
        ]);

        $this->assertSame(1, Artisan::call(
            'shop:withdraw-admin-restriction',
            $this->commitArgs($shop, $holder, $this->makeAdmin(), $partial->id)
        ));

        $this->assertStringContainsString('omits', Artisan::output());
        $this->assertSame($holder->id, $shop->fresh()->suspended_by);
    }

    public function test_it_refuses_a_held_shop_with_no_access_relevant_audit_history(): void
    {
        [$shop, $holder] = $this->heldShop(withHistory: false);

        $this->artisan('shop:withdraw-admin-restriction', [
            'shop'                  => $shop->id,
            '--authorized-by'       => $this->makeAdmin()->id,
            '--reason'              => 'Applied in error',
            '--expect-suspended-by' => $holder->id,
            '--expect-updated-at'   => $shop->updated_at->format('Y-m-d H:i:s'),
            '--expect-audit-event'  => 1,
            '--commit'              => true,
        ])->assertExitCode(1);

        $this->assertSame($holder->id, $shop->fresh()->suspended_by);
    }

    /**
     * Audit rows are keyed by (target_type, target_id). A PlatformAdmin — or any
     * other audited model — sharing the shop's id must not be read as this shop's
     * history.
     */
    public function test_history_is_scoped_by_target_type_not_just_id(): void
    {
        [$shop, $holder, $event] = $this->heldShop();

        PlatformAuditLog::create([
            'actor_admin_id' => $holder->id,
            'action'         => 'platform_admin.updated',
            'target_type'    => PlatformAdmin::class,
            'target_id'      => $shop->id,
            'before'         => ['access_mode' => 'active', 'suspended_by' => null],
            'after'          => ['access_mode' => 'suspended', 'suspended_by' => 4242],
            'created_at'     => now(),
        ]);

        // The foreign row is the highest id, so if it leaked into the history the
        // event binding below would refuse and reconciliation would fail.
        $this->artisan('shop:withdraw-admin-restriction', $this->commitArgs($shop, $holder, $this->makeAdmin(), $event->id))
            ->assertExitCode(0);

        $this->assertNull($shop->fresh()->suspended_by);
    }

    // ════════════════════════════════════════════════════════════════
    // Dry run
    // ════════════════════════════════════════════════════════════════

    public function test_the_dry_run_shows_the_proposed_result_and_writes_absolutely_nothing(): void
    {
        [$shop, $holder] = $this->heldShop();
        $auditCount = PlatformAuditLog::count();
        $rowBefore = $this->rawRow($shop->id);

        // Artisan::output() over expectsOutputToContain(): the latter mocks the
        // OutputStyle, and what we need to assert on is the REAL rendered tables.
        $this->assertSame(0, Artisan::call('shop:withdraw-admin-restriction', ['shop' => $shop->id]));
        $output = Artisan::output();

        // The proposed column must show the RESULT, not a second copy of the
        // current state — the whole point of simulating on an uncached instance.
        $this->assertStringContainsString('admin_suspended', $output);
        $this->assertStringContainsString('subscription_lapse', $output);
        $this->assertMatchesRegularExpression('/owner may buy a plan\s*\|\s*false\s*\|\s*true/', $output);
        $this->assertMatchesRegularExpression('/entitled to ERP today\s*\|\s*false\s*\|\s*false/', $output);
        // The reviewed event is identified, and the limits of the history stated.
        $this->assertStringContainsString('WITHDRAWING', $output);
        $this->assertStringContainsString('does NOT guarantee no write occurred', $output);

        // The COMPLETE raw row, column for column, read past Eloquent. Spot-checking
        // a couple of columns (or leaning on updated_at, which is timestamp(0) and
        // so cannot distinguish a write landing in the same second) would not
        // establish "writes absolutely nothing" — this does.
        $this->assertSame($rowBefore, $this->rawRow($shop->id), 'The dry run must not write anything at all.');
        $this->assertSame($auditCount, PlatformAuditLog::count());

        $shop = $shop->fresh();
        $this->assertSame($holder->id, $shop->suspended_by);
        $this->assertSame('suspended', $shop->access_mode);
    }

    // ════════════════════════════════════════════════════════════════
    // Blast radius
    // ════════════════════════════════════════════════════════════════

    /**
     * Shop::booted() registers a `saving` hook that lowercases and trims
     * owner_email / shop_email. An Eloquent save would therefore rewrite customer
     * contact data alongside the correction, so the command uses a query-builder
     * update instead. This pins that: an unnormalised address survives untouched.
     */
    public function test_it_writes_only_suspended_by_and_updated_at(): void
    {
        [$shop, $holder, $event] = $this->heldShop();

        // Planted with the query builder so the saving hook cannot normalise it on
        // the way in — this is what a legacy production row looks like.
        Shop::query()->whereKey($shop->id)->update(['owner_email' => '  MiXeD@Example.COM ']);

        $before = $this->rawRow($shop->id);
        $args = $this->commitArgs($shop->fresh(), $holder, $this->makeAdmin(), $event->id);

        $this->artisan('shop:withdraw-admin-restriction', $args)->assertExitCode(0);

        $after = $this->rawRow($shop->id);

        $this->assertSame('  MiXeD@Example.COM ', $after['owner_email'], 'The email-normalisation hook must not have fired.');
        $this->assertNull($after['suspended_by']);

        $changed = array_keys(array_diff_assoc(
            array_map(fn ($v) => (string) $v, $after),
            array_map(fn ($v) => (string) $v, $before)
        ));
        sort($changed);

        // suspended_by is the change. updated_at is the bookkeeping stamp the
        // query builder refreshes, and it is ALLOWED but not required to appear:
        // shops.updated_at is timestamp(0), so a write inside the same second as
        // the previous one is byte-identical. Nothing else may move.
        $this->assertContains('suspended_by', $changed);
        $this->assertEmpty(
            array_diff($changed, ['suspended_by', 'updated_at']),
            'Only suspended_by and the updated_at stamp may change: ' . implode(', ', $changed)
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Standing refusals
    // ════════════════════════════════════════════════════════════════

    public function test_it_refuses_when_enforcement_is_off_because_the_shop_would_auto_restore(): void
    {
        [$shop, $holder, $event] = $this->heldShop();
        config()->set('platform.enforce_subscriptions', false);

        $this->artisan('shop:withdraw-admin-restriction', $this->commitArgs($shop, $holder, $this->makeAdmin(), $event->id))
            ->assertExitCode(1);

        $this->assertSame($holder->id, $shop->fresh()->suspended_by);
    }

    public function test_it_refuses_a_hold_whose_reason_does_not_corroborate_a_lapse(): void
    {
        // A genuine administrative hold. Withdrawing the attribution would leave
        // it unattributed-and-unexplained, so the command must not touch it — and
        // must not invent a "Subscription …" reason to make it fit.
        [$shop, $holder, $event] = $this->heldShop(reason: 'Compliance hold — fraud review');

        $this->artisan('shop:withdraw-admin-restriction', $this->commitArgs($shop, $holder, $this->makeAdmin(), $event->id))
            ->assertExitCode(1);

        $shop = $shop->fresh();
        $this->assertSame($holder->id, $shop->suspended_by);
        $this->assertSame('Compliance hold — fraud review', $shop->suspension_reason);
    }

    public function test_it_refuses_a_shop_that_is_not_administratively_held(): void
    {
        [$shop, $holder, $event] = $this->heldShop();
        Shop::query()->whereKey($shop->id)->update(['suspended_by' => null]);

        $this->artisan('shop:withdraw-admin-restriction', $this->commitArgs($shop->fresh(), $holder, $this->makeAdmin(), $event->id))
            ->assertExitCode(1);
    }

    public function test_it_refuses_a_shop_whose_term_still_covers_today(): void
    {
        [$shop, $holder, $event] = $this->heldShop();
        $this->latestSubscription($shop)->update([
            'status'  => 'active',
            'ends_at' => now()->addMonths(3)->toDateString(),
        ]);

        $this->artisan('shop:withdraw-admin-restriction', $this->commitArgs($shop, $holder, $this->makeAdmin(), $event->id))
            ->assertExitCode(1);

        $this->assertSame($holder->id, $shop->fresh()->suspended_by);
    }
}
