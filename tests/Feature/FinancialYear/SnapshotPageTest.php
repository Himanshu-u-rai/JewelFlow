<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\FiscalYearSnapshotService;
use App\Support\FinancialYear;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** The owner's read-only "Year-end snapshot" page. Owner only; refusals carry no explanation (owner rule 2026-10-30). */
class SnapshotPageTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant, SnapshotTestHelpers;

    private User $owner;
    private Shop $shop;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        [$this->owner, $this->shop] = $this->createRetailerTenant();
        $this->today();
    }

    private function as(User $u, string $method, string $url, array $data = [])
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->actingAs($u)->{$method}($url, $data));
    }

    private function staff(): User
    {
        $role = new Role();
        $role->forceFill(['name' => 'manager', 'display_name' => 'Manager', 'shop_id' => $this->shop->id])->save();
        $user = User::factory()->create(['shop_id' => $this->shop->id, 'role_id' => $role->id, 'is_active' => true]);
        $this->grantOnlyPermissions($user, Permission::query()->pluck('name')->all());

        return $user;
    }

    public function test_owner_sees_no_snapshot_yet_with_an_explicit_take_button(): void
    {
        $res = $this->as($this->owner, 'get', route('books.year-end'));

        $res->assertOk();
        $res->assertSee('2025-26');
        $res->assertSee('No snapshot yet');
        $res->assertSee('action="' . route('books.year-end.take', ['fy' => '2025-26']) . '"', false);
        $res->assertSee('data-turbo-frame="_top"', false);
    }

    public function test_taking_a_snapshot_stores_a_closing_version_and_shows_it(): void
    {
        $this->at('2026-02-01 10:00:00');
        $this->createItem($this->shop->id, null, ['acquisition_cost' => 40000, 'acquisition_cost_source' => 'entered']);
        $this->createItem($this->shop->id, null, ['acquisition_cost' => null]);
        $this->today();

        $this->as($this->owner, 'post', route('books.year-end.take', ['fy' => '2025-26']))->assertRedirect();

        $row = DB::table('fiscal_year_snapshots')->first();
        $this->assertSame('closing', $row->kind);
        $this->assertSame((int) $this->owner->id, (int) $row->taken_by);

        $page = $this->as($this->owner, 'get', route('books.year-end', ['fy' => '2025-26']));
        $page->assertOk()->assertDontSee('No snapshot yet');
        $page->assertSee('Version 1');
        $page->assertSee('gold');
        $page->assertSeeText('1 item');          // the unknown-cost count
    }

    public function test_the_page_lists_what_changed_since_the_snapshot(): void
    {
        app(FiscalYearSnapshotService::class)->take($this->shop->id, FinancialYear::fromLabel('2025-26'), 'closing');
        $this->at('2026-03-15 10:00:00');
        $this->createItem($this->shop->id, null, ['barcode' => 'LATE-77']);
        $this->today();

        $this->as($this->owner, 'get', route('books.year-end', ['fy' => '2025-26']))->assertOk()->assertSee('LATE-77');
    }

    public function test_an_unfinished_year_cannot_be_snapshotted_and_nothing_is_stored(): void
    {
        $this->as($this->owner, 'post', route('books.year-end.take', ['fy' => '2026-27']))->assertSessionHasErrors();
        $this->assertSame(0, DB::table('fiscal_year_snapshots')->count());
    }

    public function test_a_garbage_year_is_404(): void
    {
        $this->as($this->owner, 'get', '/books/year-end/banana')->assertNotFound();
        $this->as($this->owner, 'get', '/books/year-end/2025-27')->assertNotFound();
        $this->as($this->owner, 'post', '/books/year-end/banana/snapshot')->assertNotFound();
    }

    public function test_non_owner_gets_403_even_with_every_permission_and_no_explanation(): void
    {
        $manager = $this->staff();

        $get = $this->as($manager, 'get', route('books.year-end'));
        $get->assertForbidden();
        $this->assertStringNotContainsStringIgnoringCase('owner', $get->getContent());
        $this->as($manager, 'post', route('books.year-end.take', ['fy' => '2025-26']))->assertForbidden();
        $this->assertSame(0, DB::table('fiscal_year_snapshots')->count());
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('books.year-end'))->assertRedirect();
        $this->post(route('books.year-end.take', ['fy' => '2025-26']))->assertRedirect();
        $this->assertSame(0, DB::table('fiscal_year_snapshots')->count());
    }

    public function test_another_shops_snapshots_are_never_shown(): void
    {
        [$otherOwner, $other] = $this->createRetailerTenant();
        app(FiscalYearSnapshotService::class)->take($other->id, FinancialYear::fromLabel('2025-26'), 'closing');

        $this->as($this->owner, 'get', route('books.year-end', ['fy' => '2025-26']))->assertSee('No snapshot yet');
    }

    public function test_the_books_lock_section_links_to_the_page(): void
    {
        $html = $this->as($this->owner, 'get', route('settings.edit', ['tab' => 'billing']))->assertOk()->getContent();

        $this->assertStringContainsString(route('books.year-end'), $html);
    }
}
