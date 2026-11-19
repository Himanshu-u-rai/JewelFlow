<?php

namespace Tests\Feature\Imports;

use App\Models\Import;
use App\Models\ImportRow;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\AssertsStablePagination;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * import_rows.row_number is not unique (no index), so rows sharing a number must
 * still each show on exactly one page of the import preview. (The historical
 * import rows are unique per batch/sheet/row: no test needed.)
 */
class ImportRowsPaginationTest extends TestCase
{
    use AssertsStablePagination;
    use CreatesTestTenant;
    use RefreshDatabase;

    public function test_import_rows_with_the_same_row_number_each_appear_on_exactly_one_page(): void
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();

        $import = TenantContext::runFor($shop->id, fn () => Import::create([
            'shop_id' => $shop->id, 'type' => 'stock', 'status' => 'previewed', 'mode' => 'create', 'file_path' => 'imports/synthetic.csv',
        ]));
        $ids = collect(range(1, 250))->map(fn () => ImportRow::create([
            'import_id' => $import->id, 'shop_id' => $shop->id, 'row_number' => 2, 'status' => 'valid', 'payload' => [],
        ])->id)->all();
        $this->tieAndScramble('import_rows', $ids, ['row_number' => 2]);

        $page = fn (int $p) => TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/imports/' . $import->id . '?page=' . $p)->assertOk()->viewData('rows'))->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 100, 'import rows');
        $this->assertSame(array_slice($ids, 0, 100), $page(1));
    }
}
