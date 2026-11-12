<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranCashEntry;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopEditionAssignment;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Owner 2026-11-12: Old Loans takes a spreadsheet (CSV or Excel) of the paper book. A downloadable template; one row
 * per pledged item, rows sharing a ticket number are one loan; a preview shows every loan with its problems before
 * anything is saved; bringing them in saves the good ones and skips the rest, saying why.
 */
class DhiranOldLoanImportTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://dhiran.jewelflows.com';
    private const HEADER = ['ticket_number', 'loan_date', 'customer_name', 'mobile', 'address', 'pan', 'id_number', 'amount_lent',
        'principal_owed', 'interest_rate_monthly', 'interest_type', 'tenure_months', 'interest_paid_up_to', 'unpaid_interest',
        'item_description', 'metal', 'purity', 'gross_weight_g', 'stone_weight_g', 'rate_per_gram', 'appraised_value'];
    private Shop $shop;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create(['name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390021000', 'owner_first_name' => 'O',
            'owner_last_name' => 'W', 'owner_mobile' => '9390021000', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create(['mobile_number' => '9390021000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'name' => 'Owner',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id]);
    }

    private function in(callable $fn)
    {
        return TenantContext::runFor($this->shop->id, function () use ($fn) {
            $this->actingAs($this->owner);

            return $fn();
        });
    }

    private function csv(array $rows): UploadedFile
    {
        $lines = array_map(fn ($r) => implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $r)), [self::HEADER, ...$rows]);

        return UploadedFile::fake()->createWithContent('paper-book.csv', implode("\n", $lines) . "\n");
    }

    private function book(): array
    {
        $d = fn (int $days) => CarbonImmutable::today()->subDays($days);

        return [
            // T-1: two items, Indian date style, Indian digit grouping
            ['T-1', $d(300)->format('d-m-Y'), 'Asha Rao', '9810030001', 'Market Road', '', '', '1,00,000', '60,000', '2', 'flat', '12', $d(30)->format('d/m/Y'), '', 'Chain', 'gold', '22', '40', '', '6000', ''],
            ['T-1', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Ring', 'gold', '22', '10', '0.5', '6000', ''],
            // T-2: same borrower (same mobile), an appraised piece
            ['T-2', $d(200)->toDateString(), 'Asha Rao', '9810030001', '', '', '', '20000', '20000', '1.5', 'daily', '6', $d(10)->toDateString(), '150', 'Watch', 'other', '', '', '', '', '50000'],
            // T-3: owes more than was lent
            ['T-3', $d(100)->toDateString(), 'Bad Figures', '9810030003', '', '', '', '10000', '15000', '2', 'flat', '6', $d(5)->toDateString(), '', 'Coin', 'gold', '24', '5', '', '6000', ''],
            // T-4: no collateral row filled
            ['T-4', $d(100)->toDateString(), 'No Item', '9810030004', '', '', '', '10000', '5000', '2', 'flat', '6', $d(5)->toDateString(), '', '', '', '', '', '', '', ''],
        ];
    }

    private function upload(UploadedFile $file)
    {
        return $this->in(fn () => $this->post(self::HOST . '/dhiran/old-loans/upload', ['file' => $file]));
    }

    public function test_the_template_lists_every_column(): void
    {
        $csv = $this->in(fn () => $this->get(self::HOST . '/dhiran/old-loans/template'))->assertOk()->streamedContent();
        $this->assertSame(self::HEADER, str_getcsv(strtok($csv, "\n")));
    }

    public function test_the_preview_shows_every_loan_and_its_problems_before_anything_is_saved(): void
    {
        $preview = $this->upload($this->csv($this->book()))->assertRedirect();
        $page = $this->in(fn () => $this->get($preview->headers->get('Location')))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-ticket="T-1"[^>]*data-ok="1"/', $page);
        $this->assertMatchesRegularExpression('/data-ticket="T-2"[^>]*data-ok="1"/', $page);
        $this->assertMatchesRegularExpression('/data-ticket="T-3"[^>]*data-ok="0"/', $page);
        $this->assertMatchesRegularExpression('/data-ticket="T-4"[^>]*data-ok="0"/', $page);
        $this->assertStringContainsString('Row 5', $page, 'problems name the spreadsheet row');
        $this->assertStringContainsString('2 loans ready', $page);
        $this->assertSame(0, DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count(), 'nothing saved by a preview');
    }

    public function test_bringing_them_in_saves_the_good_loans_and_skips_the_rest(): void
    {
        $preview = $this->upload($this->csv($this->book()))->headers->get('Location');
        $confirm = str_replace('/dhiran/old-loans/upload/', '/dhiran/old-loans/upload/', $preview);
        $this->in(fn () => $this->post($confirm, ['idempotency_key' => 'import-0001']))
            ->assertRedirect(route('dhiran.old-loans.index'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '2 old loans brought in') && str_contains($m, '2 skipped'));

        $loans = DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->orderBy('loan_number')->get();
        $this->assertSame(['T-1', 'T-2'], $loans->pluck('loan_number')->all());
        $t1 = $loans[0];
        $this->assertSame(CarbonImmutable::today()->subDays(300)->toDateString(), $t1->loan_date->toDateString());
        $this->assertSame(100000.0, (float) $t1->principal_amount);
        $this->assertSame(60000.0, (float) $t1->outstanding_principal);
        $this->assertSame(CarbonImmutable::today()->subDays(30)->toDateString(), $t1->interest_accrued_through->toDateString());
        $this->assertSame(2, $t1->items()->withoutGlobalScopes()->count(), 'rows sharing a ticket are one loan');
        $this->assertTrue((bool) $t1->brought_forward);
        $this->assertSame('active', $t1->status);
        $this->assertSame(150.0, (float) $loans[1]->outstanding_interest, "the shop's own figure");
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count(), 'one borrower for both tickets');
        $this->assertSame(0, DhiranCashEntry::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());

        // Sending the same file again brings nothing in twice.
        $again = $this->upload($this->csv($this->book()))->headers->get('Location');
        $this->in(fn () => $this->post($again, ['idempotency_key' => 'import-0002']))->assertSessionHas('success', fn ($m) => str_contains($m, '0 old loans brought in'));
        $this->assertSame(2, DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
    }

    public function test_an_excel_file_works_too(): void
    {
        $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet->getActiveSheet()->fromArray([self::HEADER, ...array_slice($this->book(), 0, 2)]);
        $path = tempnam(sys_get_temp_dir(), 'ob') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($path);

        $preview = $this->upload(new UploadedFile($path, 'paper-book.xlsx', null, null, true))->assertRedirect()->headers->get('Location');
        $this->assertMatchesRegularExpression('/data-ticket="T-1"[^>]*data-ok="1"/', $this->in(fn () => $this->get($preview))->getContent());
    }

    public function test_upload_waits_for_a_paid_plan_and_only_takes_spreadsheets(): void
    {
        $this->upload(UploadedFile::fake()->create('photo.jpg', 10, 'image/jpeg'))->assertSessionHasErrors('file');

        ShopEditionAssignment::where('shop_id', $this->shop->id)->where('edition', 'dhiran')->update(['deactivated_at' => now()]);
        $this->owner = $this->owner->fresh();
        $this->in(fn () => $this->get(self::HOST . '/dhiran/old-loans/upload'))->assertRedirect(route('dhiran.dashboard'));
        $this->upload($this->csv($this->book()))->assertRedirect(route('dhiran.dashboard'));
    }
}
