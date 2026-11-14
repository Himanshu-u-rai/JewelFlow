<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit N23: UserObserver silently rewrote is_active to match employment_status, so a "disable by is_active only" was
 * quietly undone, and raw bulk updates skipped the observer entirely. is_active is what login and EnsureAccountIsActive
 * read; employment_status says why. The pair must agree: an inconsistent save is refused, and the database refuses a
 * raw write the same way.
 */
class UserActiveEmploymentConsistencyTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function activeUser(): User
    {
        [, $shop] = $this->createRetailerTenant();

        return User::factory()->create(['shop_id' => $shop->id, 'is_active' => true, 'employment_status' => 'active']);
    }

    public function test_disabling_by_is_active_alone_is_refused_not_silently_undone(): void
    {
        $user = $this->activeUser();

        try {
            $user->is_active = false;
            $user->save();
            $this->fail('an inconsistent save must be refused');
        } catch (\DomainException) {
            // expected
        }
        $this->assertTrue((bool) DB::table('users')->where('id', $user->id)->value('is_active'));
    }

    public function test_enabling_a_terminated_user_by_is_active_alone_is_refused(): void
    {
        $user = $this->activeUser();
        $user->forceFill(['is_active' => false, 'employment_status' => 'terminated'])->save();

        try {
            $user->forceFill(['is_active' => true])->save();
            $this->fail('an inconsistent save must be refused');
        } catch (\DomainException) {
            // expected
        }
        $this->assertFalse((bool) DB::table('users')->where('id', $user->id)->value('is_active'));
    }

    public function test_consistent_transitions_still_save(): void
    {
        $user = $this->activeUser();
        $user->forceFill(['is_active' => false, 'employment_status' => 'suspended'])->save();
        $user->forceFill(['is_active' => true, 'employment_status' => 'active'])->save();
        $user->forceFill(['is_active' => false, 'employment_status' => 'terminated'])->save();

        $this->assertSame('terminated', $user->fresh()->employment_status);
        $this->assertFalse((bool) $user->fresh()->is_active);
    }

    public function test_a_raw_update_that_skips_the_observer_is_refused_by_the_database(): void
    {
        $user = $this->activeUser();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('users')->where('id', $user->id)->update(['is_active' => DB::raw('false')]);
    }

    public function test_the_migration_stops_and_lists_existing_inconsistent_rows_without_rewriting_them(): void
    {
        $user = $this->activeUser();
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_active_matches_employment_check');
        DB::table('users')->where('id', $user->id)->update(['is_active' => DB::raw('false')]);

        $migration = require base_path('database/migrations/2026_11_14_110000_users_active_matches_employment_status.php');
        try {
            $migration->up();
            $this->fail('the migration must stop on inconsistent rows');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString((string) $user->id, $e->getMessage());
        }
        $this->assertFalse((bool) DB::table('users')->where('id', $user->id)->value('is_active'), 'row left as found');
        $this->assertSame('active', DB::table('users')->where('id', $user->id)->value('employment_status'));
    }
}
