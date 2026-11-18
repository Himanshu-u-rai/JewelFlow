<?php

namespace Tests\Feature\FinancialYear;

use App\Services\FinancialLockService;
use App\Services\FiscalYearSnapshotService;
use App\Support\FinancialYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Only the most recent CLOSED financial year (one with a 'closing' snapshot) can be reopened. Reopening an
 * older one would silently change the opening figures of every closed year after it (owner, 2026-11-16).
 */
class ReopenClosedYearTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private $owner;
    private $shop;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [$this->owner, $this->shop] = $this->createRetailerTenant();
        $this->travelTo(CarbonImmutable::parse('2027-06-10 12:00:00', 'Asia/Kolkata'));
    }

    private function close(string $fy): void
    {
        app(FiscalYearSnapshotService::class)->take($this->shop->id, FinancialYear::fromLabel($fy), FiscalYearSnapshotService::KIND_CLOSING, $this->owner->id);
    }

    private function lock(string $date): void
    {
        app(FinancialLockService::class)->lock($this->shop, $date, $this->owner, 'closing the books');
    }

    private function unlock(?string $to): void
    {
        app(FinancialLockService::class)->unlock($this->shop, $to, $this->owner, 'correcting a purchase entry');
    }

    private function lockDate(): ?string
    {
        $d = DB::table('shop_rules')->where('shop_id', $this->shop->id)->value('financial_lock_date');

        return $d ? substr((string) $d, 0, 10) : null;
    }

    public function test_the_latest_closed_year_can_be_reopened(): void
    {
        $this->close('2025-26');
        $this->close('2026-27');
        $this->lock('2027-03-31');

        $this->unlock('2026-05-31'); // inside 2026-27, the latest closed year

        $this->assertSame('2026-05-31', $this->lockDate());
    }

    public function test_reopening_into_the_start_of_the_latest_closed_year_is_allowed(): void
    {
        $this->close('2025-26');
        $this->close('2026-27');
        $this->lock('2027-03-31');

        $this->unlock('2026-03-31'); // the whole of 2026-27 open, 2025-26 still shut

        $this->assertSame('2026-03-31', $this->lockDate());
    }

    public function test_an_older_closed_year_cannot_be_reopened_while_a_later_one_is_closed(): void
    {
        $this->close('2025-26');
        $this->close('2026-27');
        $this->lock('2027-03-31');

        try {
            $this->unlock('2025-12-31');
            $this->fail('An older closed year was reopened.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('2026-27', $e->getMessage());
        }
        $this->assertSame('2027-03-31', $this->lockDate());
    }

    public function test_opening_the_books_completely_is_refused_when_a_closed_year_would_reopen_behind_another(): void
    {
        $this->close('2025-26');
        $this->close('2026-27');
        $this->lock('2027-03-31');

        $this->expectException(InvalidArgumentException::class);
        $this->unlock(null);
    }

    public function test_with_one_closed_year_the_books_can_be_opened_fully(): void
    {
        $this->close('2026-27');
        $this->lock('2027-03-31');

        $this->unlock(null);

        $this->assertNull($this->lockDate());
    }

    public function test_automatic_snapshots_do_not_count_as_closing(): void
    {
        app(FiscalYearSnapshotService::class)->take($this->shop->id, FinancialYear::fromLabel('2025-26'), FiscalYearSnapshotService::KIND_AUTOMATIC);
        app(FiscalYearSnapshotService::class)->take($this->shop->id, FinancialYear::fromLabel('2026-27'), FiscalYearSnapshotService::KIND_AUTOMATIC);
        $this->lock('2027-03-31');

        $this->unlock('2025-12-31'); // nothing was closed by the owner

        $this->assertSame('2025-12-31', $this->lockDate());
    }
}
