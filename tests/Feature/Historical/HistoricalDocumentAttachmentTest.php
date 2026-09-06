<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesDocumentAttachment;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 5 — evidence attachments. Proves storage never uses the public disk,
 * streaming/removal are shop-scoped and permission-gated, and the DB's
 * all-or-nothing removal-metadata CHECK constraint matches what the model
 * actually writes.
 */
class HistoricalDocumentAttachmentTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        Storage::fake('local');
    }

    private function makeBatch(int $shopId): HistoricalImportBatch
    {
        $batch = new HistoricalImportBatch;
        $batch->forceFill([
            'shop_id' => $shopId,
            'label' => 'FY 2023-24',
            'source_system' => 'Manual',
            'status' => HistoricalImportBatch::STATUS_DRAFT,
        ])->save();

        return $batch;
    }

    private function makeDocument(int $shopId, int $batchId, array $attrs = []): HistoricalSalesDocument
    {
        $number = $attrs['original_document_number'] ?? ('DOC-'.Str::random(8));
        $date = $attrs['document_date'] ?? '2023-11-04';
        $normalized = HistoricalDocumentIdentity::normalizeNumber($number);
        $fy = HistoricalDocumentIdentity::financialYearFor(new \DateTimeImmutable($date));
        $status = $attrs['status'] ?? HistoricalSalesDocument::STATUS_DRAFT;

        $document = new HistoricalSalesDocument;
        $document->forceFill(array_merge([
            'shop_id' => $shopId,
            'historical_import_batch_id' => $batchId,
            'historical_reference' => (string) Str::uuid(),
            'original_document_number' => $number,
            'original_document_number_normalized' => $normalized,
            'document_type' => HistoricalSalesDocument::TYPE_SALE_INVOICE,
            'document_date' => $date,
            'financial_year' => $fy,
            'source_system' => 'Manual',
            'customer_snapshot' => ['name' => 'Ramesh Patel'],
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'tax_completeness' => HistoricalSalesDocument::TAX_UNKNOWN,
            'grand_total' => 25000.00,
            'status' => HistoricalSalesDocument::STATUS_DRAFT,
            'content_fingerprint' => hash('sha256', (string) Str::uuid()),
            'published_at' => in_array($status, [
                HistoricalSalesDocument::STATUS_PUBLISHED,
                HistoricalSalesDocument::STATUS_SUPERSEDED,
            ], true) ? now() : null,
        ], $attrs))->save();

        return $document;
    }

    private function fakeUpload(): UploadedFile
    {
        return UploadedFile::fake()->create('bill-scan.pdf', 100, 'application/pdf');
    }

    public function test_upload_stores_on_the_private_local_disk_never_public(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        $response = $this->actingAs($owner)->post(
            route('historical.documents.attachments.store', $document),
            ['file' => $this->fakeUpload()]
        );
        $response->assertRedirect();

        $attachment = TenantContext::runFor($shop->id, fn () => HistoricalSalesDocumentAttachment::first());
        $this->assertNotNull($attachment);
        $this->assertSame('local', $attachment->file_disk);
        Storage::disk('local')->assertExists($attachment->file_path);
        $this->assertTrue($attachment->is_active);
    }

    public function test_upload_requires_historical_import_permission(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($owner, ['historical.view']);
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        $this->actingAs($owner)
            ->post(route('historical.documents.attachments.store', $document), ['file' => $this->fakeUpload()])
            ->assertForbidden();
    }

    public function test_streaming_requires_historical_view_permission_and_serves_the_file(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        $this->actingAs($owner)->post(
            route('historical.documents.attachments.store', $document),
            ['file' => $this->fakeUpload()]
        );
        $attachment = TenantContext::runFor($shop->id, fn () => HistoricalSalesDocumentAttachment::first());

        $this->grantOnlyPermissions($owner, []);
        $this->actingAs($owner)
            ->get(route('historical.attachments.show', $attachment))
            ->assertForbidden();

        $this->grantOnlyPermissions($owner, ['historical.view']);
        $this->actingAs($owner)
            ->get(route('historical.attachments.show', $attachment))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_cross_shop_attachment_access_is_404_not_403(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();

        $documentA = TenantContext::runFor($shopA->id, fn () => $this->makeDocument($shopA->id, $this->makeBatch($shopA->id)->id));
        $this->actingAs($ownerA)->post(
            route('historical.documents.attachments.store', $documentA),
            ['file' => $this->fakeUpload()]
        );
        $attachmentA = TenantContext::runFor($shopA->id, fn () => HistoricalSalesDocumentAttachment::first());

        $this->actingAs($ownerB)
            ->get(route('historical.attachments.show', $attachmentA))
            ->assertNotFound();

        $this->actingAs($ownerB)
            ->delete(route('historical.attachments.destroy', $attachmentA), ['reason' => 'x'])
            ->assertNotFound();
    }

    public function test_removal_requires_a_reason_deletes_the_file_and_keeps_the_audited_row(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        $this->actingAs($owner)->post(
            route('historical.documents.attachments.store', $document),
            ['file' => $this->fakeUpload()]
        );
        $attachment = TenantContext::runFor($shop->id, fn () => HistoricalSalesDocumentAttachment::first());
        $path = $attachment->file_path;

        // Blank reason is rejected by validation before the model is ever touched.
        $this->actingAs($owner)
            ->delete(route('historical.attachments.destroy', $attachment), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($owner)
            ->delete(route('historical.attachments.destroy', $attachment), ['reason' => 'Wrong bill attached'])
            ->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($attachment, $owner): void {
            $fresh = $attachment->fresh();
            $this->assertFalse($fresh->is_active);
            $this->assertSame('Wrong bill attached', $fresh->removed_reason);
            $this->assertSame($owner->id, $fresh->removed_by);
            $this->assertNotNull($fresh->removed_at);
        });
        Storage::disk('local')->assertMissing($path);

        // Streaming a removed attachment now 404s even for a fully-permitted user.
        $this->actingAs($owner)
            ->get(route('historical.attachments.show', $attachment))
            ->assertNotFound();
    }

    public function test_removal_is_blocked_on_an_already_removed_attachment(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        $this->actingAs($owner)->post(
            route('historical.documents.attachments.store', $document),
            ['file' => $this->fakeUpload()]
        );
        $attachment = TenantContext::runFor($shop->id, fn () => HistoricalSalesDocumentAttachment::first());

        $this->actingAs($owner)
            ->delete(route('historical.attachments.destroy', $attachment), ['reason' => 'first removal'])
            ->assertRedirect();

        $this->actingAs($owner)
            ->delete(route('historical.attachments.destroy', $attachment), ['reason' => 'second attempt'])
            ->assertSessionHas('error');
    }

    public function test_removal_survives_after_the_parent_document_is_published(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id, [
            'status' => HistoricalSalesDocument::STATUS_PUBLISHED,
        ]));

        $this->actingAs($owner)->post(
            route('historical.documents.attachments.store', $document),
            ['file' => $this->fakeUpload()]
        );
        $attachment = TenantContext::runFor($shop->id, fn () => HistoricalSalesDocumentAttachment::first());

        // Attachments are not gated by ImmutableWhenPublished — removal on a
        // published document's evidence must still work (unlike payments).
        $this->actingAs($owner)
            ->delete(route('historical.attachments.destroy', $attachment), ['reason' => 'Retracted post-publish'])
            ->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($attachment): void {
            $this->assertFalse($attachment->fresh()->is_active);
        });
    }

    /**
     * Direct raw-write proof that the DB CHECK constraint
     * (historical_attachments_removal_metadata_check) rejects a half-filled
     * removal, independent of anything the model/service enforce in PHP.
     */
    public function test_db_check_constraint_rejects_half_filled_removal_metadata(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        $this->expectException(\Illuminate\Database\QueryException::class);

        TenantContext::runFor($shop->id, function () use ($shop, $document): void {
            \Illuminate\Support\Facades\DB::table('historical_sales_document_attachments')->insert([
                'shop_id' => $shop->id,
                'historical_sales_document_id' => $document->id,
                'file_path' => 'x/y.pdf',
                'file_disk' => 'local',
                'is_active' => false,
                // removed_at/removed_by/removed_reason all left null — must be rejected.
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
