<?php

namespace Tests\Feature\Admin;

use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Guards the Super Admin dashboard's RESTRICTION SUMMARY: the read-only KPI card
 * and the two alert tables. Sibling of SuperAdminAccessModeBadgeTest, which pins
 * the per-shop access-mode badge; this file pins the two things that badge work
 * deliberately left open.
 *
 * Two separate defects, both on /admin:
 *
 * 1. THE REASON COLUMN ALWAYS RENDERED "—".  Both alert tables were built with
 *    Eloquent's get(['col', ...]), which restricts the SELECT list. An unselected
 *    column reads back as null with NO error, so suspension_reason — the whole
 *    point of a column headed "Reason" — was silently absent on every row. A
 *    compliance hold and a blank reason looked identical.
 *
 * 2. THE READ-ONLY KPI WAS ONE UNDIFFERENTIATED NUMBER described as "Writes
 *    blocked". It merged two situations an admin must answer differently: a shop
 *    an admin deliberately held (NOT self-recoverable — the owner cannot buy
 *    their way out) and a shop restricted with no administrative attribution at
 *    all (the JF-0001 legacy shape).
 *
 * The distinction is read off Shop::suspensionIsAdministrative() and
 * Shop::suspensionIsSubscriptionManaged() — the same classifiers the session and
 * subscription middlewares act on — rather than re-derived from a second SQL
 * predicate, so the dashboard cannot drift away from the owner's real experience.
 */
class SuperAdminDashboardRestrictionLabelsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makeAdmin(string $prefix = 'labels'): PlatformAdmin
    {
        return PlatformAdmin::create([
            'first_name' => ucfirst($prefix), 'last_name' => 'Test', 'name' => ucfirst($prefix) . ' Test',
            'email' => $prefix . random_int(1000, 999999) . '@example.com',
            'mobile_number' => '9' . random_int(100000000, 999999999),
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'super_admin', 'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function actingAsSuperAdmin(): self
    {
        return $this->actingAs($this->makeAdmin(), 'platform_admin')
            ->withSession([\App\Http\Middleware\EnsurePlatformAdminMfa::SESSION_PASSED => true]);
    }

    /**
     * createShop() names every shop "Test Shop", so a per-row assertion needs a
     * distinguishable name or it could pass on a different shop's row.
     */
    private function namedShop(string $name): Shop
    {
        $shop = $this->createShop('retailer');
        $shop->forceFill(['name' => $name])->save();

        return $shop;
    }

    /** A deliberate administrator hold: an actor is recorded on the row. */
    private function adminHeldShop(string $name, string $reason): Shop
    {
        $shop = $this->namedShop($name);

        $shop->forceFill([
            'access_mode'       => 'read_only',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => $reason,
            'suspended_by'      => $this->makeAdmin('holder')->id,
        ])->save();

        return $shop;
    }

    /**
     * The JF-0001 shape: read_only on the shop, a corroborating read_only
     * subscription row, no live term, and no administrative attribution.
     */
    private function lapsedShop(string $name): Shop
    {
        $plan = $this->createPlan('retailer');
        $shop = $this->namedShop($name);

        ShopSubscription::create([
            'shop_id'   => $shop->id,
            'plan_id'   => $plan->id,
            'status'    => 'read_only',
            'starts_at' => now()->subMonths(2)->toDateString(),
            'ends_at'   => now()->subDays(25)->toDateString(),
        ]);

        $shop->forceFill([
            'access_mode'  => 'read_only',
            'is_active'    => false,
            'suspended_by' => null,
        ])->save();

        return $shop;
    }

    // ════════════════════════════════════════════════════════════════
    // Defect 1 — the Reason column
    // ════════════════════════════════════════════════════════════════

    public function test_read_only_table_renders_the_stored_suspension_reason(): void
    {
        $this->adminHeldShop('Readonly Reason Shop', 'Compliance hold pending KYC docs');

        $this->actingAsSuperAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Readonly Reason Shop')
            // Pre-fix this row rendered the em-dash fallback: suspension_reason was
            // never in the SELECT list, so it read back as null.
            ->assertSee('Compliance hold pending KYC docs');
    }

    public function test_suspended_table_renders_the_stored_suspension_reason(): void
    {
        $shop = $this->namedShop('Suspended Reason Shop');
        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => 'Chargeback investigation open',
            'suspended_by'      => $this->makeAdmin('suspender')->id,
        ])->save();

        // The sibling table had the identical missing-column bug. Both fixed.
        $this->actingAsSuperAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Suspended Reason Shop')
            ->assertSee('Chargeback investigation open');
    }

    // ════════════════════════════════════════════════════════════════
    // Defect 2 — the read-only KPI split
    // ════════════════════════════════════════════════════════════════

    public function test_read_only_kpi_splits_admin_holds_from_unattributed_shops(): void
    {
        $this->adminHeldShop('Kpi Held Shop', 'Compliance hold');
        $this->lapsedShop('Kpi Lapsed Shop');

        $this->actingAsSuperAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            // Total unchanged; the description is what carries the split.
            ->assertSee('1 admin hold · 1 unattributed');
    }

    public function test_read_only_kpi_counts_only_read_only_shops(): void
    {
        $this->adminHeldShop('Kpi Held Shop', 'Compliance hold');

        // A suspended shop is a different KPI card. If the split predicate ever
        // drops its access_mode filter this goes red instead of quietly inflating.
        $suspended = $this->namedShop('Kpi Suspended Shop');
        $suspended->forceFill([
            'access_mode'  => 'suspended',
            'is_active'    => false,
            'suspended_by' => $this->makeAdmin('other')->id,
        ])->save();

        $this->actingAsSuperAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('1 admin hold · 0 unattributed');
    }

    // ════════════════════════════════════════════════════════════════
    // The per-row Cause column
    //
    // The KPI is deliberately coarse — it splits on suspended_by alone, which
    // needs no joins. The table is where the real classifier runs, so a lapse and
    // an unattributed legacy row are told apart here and nowhere else.
    // ════════════════════════════════════════════════════════════════

    public function test_read_only_table_marks_an_administrative_hold(): void
    {
        $shop = $this->adminHeldShop('Cause Held Shop', 'Compliance hold');

        $this->assertTrue($shop->fresh()->suspensionIsAdministrative());

        // Scoped to this table: the sibling Suspended table renders the same three
        // labels from the same component, so a page-wide match proves nothing.
        $html = $this->tableHtml(
            $this->actingAsSuperAdmin()->get(route('admin.dashboard'))->assertOk()->getContent(),
            'Read-Only Shops'
        );

        $this->assertStringContainsString('Cause Held Shop', $html);
        $this->assertStringContainsString('Admin hold', $html);
        $this->assertStringNotContainsString('Subscription lapse', $html);
    }

    public function test_read_only_table_marks_a_subscription_lapse(): void
    {
        $shop = $this->lapsedShop('Cause Lapsed Shop');

        $this->assertTrue($shop->fresh()->suspensionIsSubscriptionManaged());

        $html = $this->tableHtml(
            $this->actingAsSuperAdmin()->get(route('admin.dashboard'))->assertOk()->getContent(),
            'Read-Only Shops'
        );

        $this->assertStringContainsString('Cause Lapsed Shop', $html);
        $this->assertStringContainsString('Subscription lapse', $html);
        // The expensive direction of the error: a lapse presented as a hold reads
        // as "deliberate, leave it alone" for a shop that can already recover
        // unaided.
        $this->assertStringNotContainsString('Admin hold', $html);
    }

    public function test_read_only_table_marks_an_unattributed_shop_as_unattributed(): void
    {
        // read_only, no actor, and no corroborating subscription row — the
        // classifier fails closed, so the dashboard must not invent an attribution.
        $shop = $this->namedShop('Cause Unknown Shop');
        $shop->forceFill([
            'access_mode'       => 'read_only',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => 'Manual lockout',
            'suspended_by'      => null,
        ])->save();

        $shop = $shop->fresh();
        $this->assertFalse($shop->suspensionIsAdministrative());
        $this->assertFalse($shop->suspensionIsSubscriptionManaged());

        $html = $this->tableHtml(
            $this->actingAsSuperAdmin()->get(route('admin.dashboard'))->assertOk()->getContent(),
            'Read-Only Shops'
        );

        $this->assertStringContainsString('Cause Unknown Shop', $html);
        $this->assertStringContainsString('Unattributed', $html);
        $this->assertStringContainsString('Manual lockout', $html);
        $this->assertStringNotContainsString('Admin hold', $html);
        $this->assertStringNotContainsString('Subscription lapse', $html);
    }

    // ════════════════════════════════════════════════════════════════
    // The Cause column on the SUSPENDED table
    //
    // The read-only table has explained cause since the earlier release; the
    // suspended one never did — it listed shop, owner, reason and timestamp under
    // "Immediate attention required" and left the operator to infer the rest from
    // whatever free text happened to be in suspension_reason.
    //
    // That inference is wrong by default. CheckSubscriptionExpiry::
    // applyShopModeUnderLock() writes access_mode='suspended' on a grace lapse and
    // never stamps suspended_by, so the ordinary lapsed tenant — the one
    // EnsureAccountIsActive already sends to the plan picker, who needs nothing
    // from an administrator — appeared in the same table, in the same tone, as a
    // deliberate compliance hold. Both modes now run through the same classifier.
    // ════════════════════════════════════════════════════════════════

    /**
     * The dashboard renders TWO cause tables side by side, from the same
     * component and the same three labels. A page-wide assertSee() therefore
     * proves nothing about which table it found the label in: a lapse label
     * leaking into the Suspended table would still pass on the Read-Only
     * table's row. Assertions below are made against one table's markup.
     *
     * Located by the panel heading rather than by an nth-child position, so
     * reordering the two panels does not silently swap what is under test.
     */
    private function tableHtml(string $html, string $title): string
    {
        // One parse, one document: saveHTML() refuses a node it did not create
        // ("Wrong Document Error"), so the node and the document that serialises
        // it have to come from the same call.
        $table = $this->table($html, $title);

        return $table->ownerDocument->saveHTML($table);
    }

    private function table(string $html, string $title): \DOMElement
    {
        $doc = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // The dashboard is a full HTML5 page; loadHTML warns about HTML5 tags it
        // does not know. The parse is still usable, and the markup under test is
        // a plain table.
        $doc->loadHTML('<?xml encoding="UTF-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $node = (new \DOMXPath($doc))->query(sprintf(
            "//h3[normalize-space(text())=%s]/ancestor::div[contains(@class,'admin-table-panel')][1]//table",
            '"' . $title . '"'
        ))->item(0);

        $this->assertInstanceOf(\DOMElement::class, $node, "No \"{$title}\" table panel on the page.");

        return $node;
    }

    /** @return array{headers:int, cells:int, colspan:?int} */
    private function tableShape(string $html, string $title): array
    {
        $table = $this->table($html, $title);
        $xpath = new \DOMXPath($table->ownerDocument);

        $row = $xpath->query('.//tbody/tr[1]', $table)->item(0);
        $colspanNode = $row ? $xpath->query('.//td[@colspan]', $row)->item(0) : null;

        return [
            'headers' => $xpath->query('.//thead/tr[1]/th', $table)->length,
            'cells'   => $row ? $xpath->query('./td', $row)->length : 0,
            'colspan' => $colspanNode instanceof \DOMElement
                ? (int) $colspanNode->getAttribute('colspan')
                : null,
        ];
    }

    /** What the expiry scheduler actually writes: suspended, a Subscription reason, no actor. */
    private function lapseSuspendedShop(string $name): Shop
    {
        $shop = $this->namedShop($name);

        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => 'Subscription grace period ended',
            'suspended_by'      => null,
        ])->save();

        return $shop;
    }

    public function test_suspended_table_marks_a_subscription_lapse(): void
    {
        $shop = $this->lapseSuspendedShop('Suspended Lapse Shop');

        $this->assertSame('subscription_lapse', $shop->fresh()->accessClassification());

        $html = $this->tableHtml(
            $this->actingAsSuperAdmin()->get(route('admin.dashboard'))->assertOk()->getContent(),
            'Suspended Shops'
        );

        $this->assertStringContainsString('Suspended Lapse Shop', $html);
        $this->assertStringContainsString('Subscription lapse', $html);
        // The costly direction: a self-recoverable lapse presented as a deliberate
        // hold tells the operator to intervene on a shop that needs nothing from
        // them — and tells the owner's support agent the opposite of what the
        // login flow will actually do.
        $this->assertStringNotContainsString('Admin hold', $html);
    }

    public function test_suspended_table_marks_an_administrative_hold(): void
    {
        $shop = $this->namedShop('Suspended Hold Shop');
        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            // Deliberately a reason that READS like a lapse. suspended_by is the
            // proof-positive marker and takes precedence over any free text, so the
            // label must not be derived from the reason string.
            'suspension_reason' => 'Subscription abuse - manual hold',
            'suspended_by'      => $this->makeAdmin('holder')->id,
        ])->save();

        $this->assertSame('admin_suspended', $shop->fresh()->accessClassification());

        $html = $this->tableHtml(
            $this->actingAsSuperAdmin()->get(route('admin.dashboard'))->assertOk()->getContent(),
            'Suspended Shops'
        );

        $this->assertStringContainsString('Suspended Hold Shop', $html);
        $this->assertStringContainsString('Admin hold', $html);
        $this->assertStringNotContainsString('Subscription lapse', $html);
    }

    public function test_suspended_table_marks_an_unattributed_suspension_as_unattributed(): void
    {
        // Suspended, no actor, and a reason the scheduler would never write. The
        // classifier fails closed, and so must the column: "Unattributed", never
        // "None" and never a guess in either direction.
        $shop = $this->namedShop('Suspended Unknown Shop');
        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => 'Manual lockout',
            'suspended_by'      => null,
        ])->save();

        $this->assertSame('unclassified_suspended', $shop->fresh()->accessClassification());

        $html = $this->tableHtml(
            $this->actingAsSuperAdmin()->get(route('admin.dashboard'))->assertOk()->getContent(),
            'Suspended Shops'
        );

        $this->assertStringContainsString('Suspended Unknown Shop', $html);
        $this->assertStringContainsString('Unattributed', $html);
        $this->assertStringContainsString('Manual lockout', $html);
        $this->assertStringNotContainsString('Admin hold', $html);
        $this->assertStringNotContainsString('Subscription lapse', $html);
    }

    /**
     * The Reason column is the one the Cause column does NOT replace: cause says
     * which of three states this is, reason says what the administrator typed.
     */
    public function test_the_suspended_table_still_shows_cause_and_reason_side_by_side(): void
    {
        $shop = $this->namedShop('Suspended Both Columns Shop');
        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => 'Chargeback investigation open',
            'suspended_by'      => $this->makeAdmin('holder')->id,
        ])->save();

        $html = $this->tableHtml(
            $this->actingAsSuperAdmin()->get(route('admin.dashboard'))->assertOk()->getContent(),
            'Suspended Shops'
        );

        $this->assertStringContainsString('Cause', $html);
        $this->assertStringContainsString('Admin hold', $html);
        $this->assertStringContainsString('Chargeback investigation open', $html);
    }

    // ════════════════════════════════════════════════════════════════
    // Column alignment
    //
    // Adding a column is the easiest way to knock a table's cells out of line
    // with its headers, and the raw `:headers` array does NOT answer the
    // question: x-admin.alert-table renders the leading "#" header ITSELF, so a
    // 6-entry array is 7 rendered columns. Counting the array alone is what makes
    // a correct table look like a 6-header/7-cell mismatch. These count the
    // RENDERED th/td, which is the only number the browser cares about, and they
    // check the populated row and the empty-state colspan separately — the empty
    // state has no sibling row to look wrong against, so it drifts unnoticed.
    // ════════════════════════════════════════════════════════════════

    public function test_both_alert_tables_align_headers_with_their_cells(): void
    {
        $this->lapseSuspendedShop('Shape Suspended Shop');
        $this->adminHeldShop('Shape Read Only Shop', 'Compliance hold');

        $html = $this->actingAsSuperAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        $suspended = $this->tableShape($html, 'Suspended Shops');
        $this->assertSame(7, $suspended['headers'], 'Suspended: # + Shop, Owner, Cause, Reason, Suspended At, Action.');
        $this->assertSame($suspended['headers'], $suspended['cells'], 'Suspended: header count and body cell count must match.');

        $readOnly = $this->tableShape($html, 'Read-Only Shops');
        $this->assertSame(6, $readOnly['headers'], 'Read-Only: # + Shop, Owner, Cause, Reason, Action.');
        $this->assertSame($readOnly['headers'], $readOnly['cells'], 'Read-Only: header count and body cell count must match.');
    }

    public function test_the_empty_state_spans_every_column_in_both_tables(): void
    {
        // No restricted shops at all — both tables render their @empty row.
        $html = $this->actingAsSuperAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        $suspended = $this->tableShape($html, 'Suspended Shops');
        $this->assertSame($suspended['headers'], $suspended['colspan'],
            'A short colspan leaves the "no suspended shops" cell stopping before the last column.');

        $readOnly = $this->tableShape($html, 'Read-Only Shops');
        $this->assertSame($readOnly['headers'], $readOnly['colspan']);
    }
}
