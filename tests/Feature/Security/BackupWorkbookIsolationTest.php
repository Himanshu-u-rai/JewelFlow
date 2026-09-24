<?php

namespace Tests\Feature\Security;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * §7e, exports — the "export everything" workbook (POST /export/all), which
 * the earlier sweep listed as not read. It builds every backup data set in the
 * request, one per sheet, from the reporting dataset services.
 *
 * Two shops hold rows with names that appear nowhere else; shop A's workbook
 * is read cell by cell.
 */
class BackupWorkbookIsolationTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    /** One customer, one item and one karigar, each named with $tag. */
    private function shopWithRows(string $tag): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->createCustomer((int) $shop->id, ['first_name' => $tag.'Customer']);
        $this->createItem((int) $shop->id, null, ['design' => $tag.'Design', 'selling_price' => 5000]);
        DB::table('karigars')->insert(['shop_id' => $shop->id, 'name' => $tag.'Karigar', 'is_active' => DB::raw('true'),
            'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $shop];
    }

    private function workbookText(string $xlsx): string
    {
        $file = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        file_put_contents($file, $xlsx);
        $text = '';
        foreach (IOFactory::load($file)->getAllSheets() as $sheet) {
            $text .= $sheet->getTitle()."\n".json_encode($sheet->toArray(null, false, false, false))."\n";
        }
        unlink($file);

        return $text;
    }

    public function test_the_backup_workbook_holds_only_the_callers_shop(): void
    {
        [$ownerA, $shopA] = $this->shopWithRows('AlphaXR');
        $this->shopWithRows('BravoXR');

        $res = TenantContext::runFor((int) $shopA->id, fn () => $this->actingAs($ownerA)->post(self::ERP.'/export/all'));
        $res->assertOk();
        $text = $this->workbookText($res->streamedContent());

        foreach (['AlphaXRCustomer', 'AlphaXRDesign', 'AlphaXRKarigar'] as $own) {
            $this->assertStringContainsString($own, $text, "shop A's own {$own} is in its backup");
        }
        $this->assertStringNotContainsString('BravoXR', $text, "nothing of shop B's is in shop A's backup");
    }

    public function test_staff_without_export_permission_cannot_take_the_backup(): void
    {
        [, $shopA] = $this->shopWithRows('AlphaXR');
        $clerk = TenantContext::runFor((int) $shopA->id, function () use ($shopA) {
            $owner = \App\Models\User::withoutGlobalScopes()->where('shop_id', $shopA->id)->firstOrFail();
            $this->grantOnlyPermissions($owner, ['customers.view']);

            return $owner->fresh();
        });

        TenantContext::runFor((int) $shopA->id, fn () => $this->actingAs($clerk)->post(self::ERP.'/export/all'))
            ->assertForbidden();
    }
}
