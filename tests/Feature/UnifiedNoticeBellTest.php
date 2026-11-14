<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Notifications\Reporting\ExportReadyNotification;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * One bell for everything that needs the user (owner request, 2026-11-14). The header bell already carried the shop's
 * notices; the stock list had a second bell of its own for items needing a price review, and a finished background
 * export was only mentioned inside the report export panel. The header bell now carries all of them:
 *   - state rows (needs attention, pricing review, reorder): they leave when the cause is fixed;
 *   - messages (ready downloads): unread until downloaded or cleared, and never offered once the link expired.
 * The badge counts the rows the bell shows.
 */
class UnifiedNoticeBellTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /** A retail shop whose dashboard warnings and reorder rules are quiet, so each test sees only its own rows. */
    private function quietShop(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, fn () => $shop->preferences()->updateOrCreate(['shop_id' => $shop->id], ['low_stock_threshold' => 0]));

        return [$owner, $shop];
    }

    /** A second user of the same shop with the same role. */
    private function colleague(Shop $shop, User $owner): User
    {
        return $this->createOwnerUser($shop, \App\Models\Role::withoutGlobalScopes()->findOrFail($owner->role_id));
    }

    private function needingPriceReview(Shop $shop, int $count): void
    {
        foreach (range(1, $count) as $n) {
            $this->createItem($shop->id, null, ['barcode' => "REVIEW-{$n}", 'pricing_review_required' => true]);
        }
    }

    private function readyExport(User $user, array $over = []): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => ExportReadyNotification::class,
            'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
            'data' => json_encode($over + [
                'type' => 'reporting_export_ready', 'export_id' => 41, 'report_key' => 'sales-register',
                'download_url' => 'https://jewelflows.com/reporting/exports/41/download?signature=abc',
                'expires_at' => now()->addDays(6)->toIso8601String(), 'message' => 'Your export is ready to download.',
            ]),
            'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** The bell's own markup, so the page's other links do not count. */
    private function bell(string $html): string
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $node = (new \DOMXPath($dom))->query('//*[@data-notice-bell]')->item(0);
        $this->assertNotNull($node, 'no bell on the page');

        return $dom->saveHTML($node);
    }

    public function test_items_needing_a_price_review_are_one_row_that_opens_the_review(): void
    {
        [$owner, $shop] = $this->quietShop();
        $this->needingPriceReview($shop, 3);

        $bell = $this->bell($this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent());

        $this->assertStringContainsString('data-notice-count="1"', $bell, 'one row, however many items');
        $this->assertStringContainsString('3 items need a price review', $bell);
        $this->assertStringContainsString('href="' . e(route('inventory.items.index', ['alerts' => 'pricing'])) . '"', $bell);
    }

    public function test_someone_without_stock_access_gets_no_pricing_row(): void
    {
        [$staff, $shop] = $this->quietShop();
        $this->needingPriceReview($shop, 2);
        $this->grantOnlyPermissions($staff, []);

        $bell = $this->bell($this->actingAs($staff->fresh())->get(route('dashboard'))->assertOk()->getContent());

        $this->assertStringContainsString('data-notice-count="0"', $bell);
        $this->assertStringNotContainsString('price review', $bell);
    }

    public function test_a_ready_export_is_in_the_requesters_bell_only(): void
    {
        [$owner, $shop] = $this->quietShop();
        $other = $this->colleague($shop, $owner);
        $this->readyExport($owner);

        $bell = $this->bell($this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent());
        $this->assertStringContainsString('data-notice-count="1"', $bell);
        $this->assertStringContainsString('Sales Register is ready to download', $bell);
        $this->assertStringContainsString(e('https://jewelflows.com/reporting/exports/41/download?signature=abc'), $bell);

        $otherBell = $this->bell($this->actingAs($other)->get(route('dashboard'))->assertOk()->getContent());
        $this->assertStringNotContainsString('ready to download', $otherBell, 'a download belongs to whoever asked for it');
    }

    public function test_an_expired_download_link_is_never_offered(): void
    {
        [$owner] = $this->quietShop();
        $this->readyExport($owner, ['expires_at' => now()->subMinute()->toIso8601String()]);

        $bell = $this->bell($this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent());

        $this->assertStringContainsString('data-notice-count="0"', $bell);
        $this->assertStringNotContainsString('ready to download', $bell);
    }

    public function test_clearing_the_downloads_marks_only_this_users_read(): void
    {
        [$owner, $shop] = $this->quietShop();
        $other = $this->colleague($shop, $owner);
        $mine = $this->readyExport($owner);
        $theirs = $this->readyExport($other);

        $this->actingAs($owner)->postJson(route('notices.downloads.clear'))->assertOk()->assertJson(['count' => 0]);

        $this->assertNotNull(DB::table('notifications')->where('id', $mine)->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $theirs)->value('read_at'));
    }

    public function test_the_bell_can_be_refreshed_without_reloading_the_page(): void
    {
        [$owner, $shop] = $this->quietShop();
        $this->needingPriceReview($shop, 2);
        $this->readyExport($owner);

        $this->actingAs($owner)->getJson(route('notices.show'))->assertOk()
            ->assertJson(['count' => 2, 'reorder_count' => 0])
            ->assertJsonPath('html', fn (string $html) => str_contains($html, '2 items need a price review') && str_contains($html, 'ready to download'));
    }

    public function test_the_stock_list_has_no_bell_of_its_own(): void
    {
        [$owner, $shop] = $this->quietShop();
        $this->needingPriceReview($shop, 2);

        $this->actingAs($owner)->get(route('inventory.items.index'))->assertOk()
            ->assertDontSee('id="pricing-alert-bell"', false)
            ->assertSee('id="pricing-alert-drawer"', false);
    }
}
