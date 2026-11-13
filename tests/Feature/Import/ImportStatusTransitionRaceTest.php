<?php

namespace Tests\Feature\Import;

use App\Models\Import;
use App\Models\Shop;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit N3 (2026-11-13): "run" and "cancel" checked the import's status as the request first read it, then wrote
 * their own status unconditionally. A cancel landing in between was overwritten by "queued" and the cancelled import
 * ran; a cancel landing after the worker claimed it stamped "cancelled" on a running import and deleted its file.
 * Each move now happens only from the state it was checked against.
 */
class ImportStatusTransitionRaceTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Storage::fake();
    }

    private function previewImport(Shop $shop): Import
    {
        Storage::put('imports/race.csv', "tag\n");

        return TenantContext::runFor($shop->id, function () use ($shop) {
            $import = new Import();
            $import->forceFill([
                'shop_id' => $shop->id, 'type' => Import::TYPE_STOCK, 'file_path' => 'imports/race.csv',
                'import_reference' => 'IMP-RACE', 'status' => Import::STATUS_PREVIEW, 'valid_rows' => 1,
            ])->save();

            return $import;
        });
    }

    private function as($user, Shop $shop, string $action, Import $import, array $data = [])
    {
        return TenantContext::runFor($shop->id, fn () => $this->actingAs($user)
            ->post(self::ERP . '/imports/' . $import->id . $action, $data));
    }

    /** Right after the request loads the import, another request moves it to $status. */
    private function movedOnceLoaded(Import $import, string $status): void
    {
        $done = false;
        Event::listen('eloquent.retrieved: ' . Import::class, function (Import $loaded) use ($import, $status, &$done) {
            if ($done || $loaded->id !== $import->id) {
                return;
            }
            $done = true;
            DB::table('imports')->where('id', $import->id)->update(['status' => $status]);
        });
    }

    public function test_an_import_cancelled_meanwhile_is_not_run(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $import = $this->previewImport($shop);
        $this->movedOnceLoaded($import, Import::STATUS_CANCELLED);

        $this->as($owner, $shop, '/execute', $import, ['mode' => Import::MODE_ROW])->assertSessionHas('error');

        $this->assertSame(Import::STATUS_CANCELLED, DB::table('imports')->where('id', $import->id)->value('status'));
    }

    public function test_an_import_claimed_by_the_worker_meanwhile_is_not_cancelled_under_it(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $import = $this->previewImport($shop);
        $this->movedOnceLoaded($import, Import::STATUS_RUNNING);

        $this->as($owner, $shop, '/cancel', $import)->assertSessionHas('error');

        $this->assertSame(Import::STATUS_RUNNING, DB::table('imports')->where('id', $import->id)->value('status'));
        Storage::assertExists('imports/race.csv');
    }

    public function test_a_preview_import_still_cancels(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $import = $this->previewImport($shop);

        $this->as($owner, $shop, '/cancel', $import)->assertSessionHas('success');

        $this->assertSame(Import::STATUS_CANCELLED, DB::table('imports')->where('id', $import->id)->value('status'));
        Storage::assertMissing('imports/race.csv');
    }
}
