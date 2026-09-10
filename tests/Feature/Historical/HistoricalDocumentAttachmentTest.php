<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesDocumentAttachment;
use App\Models\Role;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
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

    /**
     * Direct-insert fixture, same posture as the CHECK-constraint test below —
     * bypasses the store() endpoint entirely so a VOID/SUPERSEDED document (on
     * which the service refuses new uploads) can still have pre-existing
     * evidence to prove read-only behavior against.
     */
    private function makeAttachment(int $shopId, int $documentId, int $uploadedBy, string $path): HistoricalSalesDocumentAttachment
    {
        Storage::disk('local')->put($path, 'fake pdf bytes');

        $attachment = new HistoricalSalesDocumentAttachment;
        $attachment->forceFill([
            'shop_id' => $shopId,
            'historical_sales_document_id' => $documentId,
            'uploaded_by' => $uploadedBy,
            'file_path' => $path,
            'file_disk' => 'local',
            'original_filename' => 'bill-scan.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'is_active' => true,
        ])->save();

        return $attachment;
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

    public function test_removal_requires_a_reason_retains_the_file_and_keeps_the_audited_row(): void
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

        // The approved contract: removal is soft. The private file must survive
        // on disk — it stays recoverable evidence — even though is_active=false
        // now blocks it from being served (see the model's class docblock).
        Storage::disk('local')->assertExists($path);

        // Streaming a removed attachment now 404s even for a fully-permitted user,
        // despite the file still being physically present.
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

    public function test_upload_rejects_a_file_over_the_10mb_limit(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        $oversized = UploadedFile::fake()->create('bill-scan.pdf', 10241, 'application/pdf');

        $this->actingAs($owner)
            ->post(route('historical.documents.attachments.store', $document), ['file' => $oversized])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, TenantContext::runFor($shop->id, fn () => HistoricalSalesDocumentAttachment::count()));
    }

    public function test_upload_rejects_a_disallowed_mime_type(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        $wrongType = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $this->actingAs($owner)
            ->post(route('historical.documents.attachments.store', $document), ['file' => $wrongType])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, TenantContext::runFor($shop->id, fn () => HistoricalSalesDocumentAttachment::count()));
    }

    public function test_guest_is_redirected_to_login_on_every_attachment_route(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));
        $attachment = TenantContext::runFor($shop->id, fn () => $this->makeAttachment($shop->id, $document->id, $owner->id, "historical-attachments/{$shop->id}/guest-test.pdf"));

        $this->post(route('historical.documents.attachments.store', $document), ['file' => $this->fakeUpload()])->assertRedirect(route('login'));
        $this->get(route('historical.attachments.show', $attachment))->assertRedirect(route('login'));
        $this->delete(route('historical.attachments.destroy', $attachment), ['reason' => 'x'])->assertRedirect(route('login'));
    }

    /**
     * A realistic reporting-only custom role (reports.view + reports.export,
     * mirroring the Batch 4 Dues Aging permission set) has no historical.*
     * grants at all — every write/removal route must still deny it.
     */
    public function test_a_reports_only_role_cannot_upload_or_remove_attachments(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($owner, ['reports.view', 'reports.export']);
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        $this->actingAs($owner)
            ->post(route('historical.documents.attachments.store', $document), ['file' => $this->fakeUpload()])
            ->assertForbidden();

        $attachment = TenantContext::runFor($shop->id, fn () => $this->makeAttachment($shop->id, $document->id, $owner->id, "historical-attachments/{$shop->id}/reports-only.pdf"));

        $this->actingAs($owner)
            ->delete(route('historical.attachments.destroy', $attachment), ['reason' => 'x'])
            ->assertForbidden();

        // historical.view alone (no reports.* overlap needed) still streams it.
        $this->grantOnlyPermissions($owner, ['historical.view']);
        $this->actingAs($owner)->get(route('historical.attachments.show', $attachment))->assertOk();
    }

    public function test_void_document_attachments_are_read_only_but_still_streamable(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id, [
            'status' => HistoricalSalesDocument::STATUS_VOID,
            'void_reason' => 'Wrong customer entirely',
            'voided_at' => now(),
        ]));
        $attachment = TenantContext::runFor($shop->id, fn () => $this->makeAttachment($shop->id, $document->id, $owner->id, "historical-attachments/{$shop->id}/void-doc.pdf"));

        $this->actingAs($owner)
            ->post(route('historical.documents.attachments.store', $document), ['file' => $this->fakeUpload()])
            ->assertRedirect();
        $this->assertSame(1, TenantContext::runFor($shop->id, fn () => HistoricalSalesDocumentAttachment::count()));

        $this->actingAs($owner)
            ->delete(route('historical.attachments.destroy', $attachment), ['reason' => 'Trying to retract'])
            ->assertRedirect();
        TenantContext::runFor($shop->id, function () use ($attachment): void {
            $this->assertTrue($attachment->fresh()->is_active);
        });

        $this->actingAs($owner)
            ->get(route('historical.attachments.show', $attachment))
            ->assertOk();
    }

    /**
     * Built via direct insert (same as the CHECK-constraint fixture below) —
     * the lifecycle trigger only fires on UPDATE/DELETE (see
     * database/migrations/2026_09_15_000200_add_historical_sales_guards.php),
     * so a document row inserted with status='superseded' never needs a live
     * draft->published->superseded transition to exist as a fixture.
     */
    public function test_superseded_document_attachments_are_read_only_but_still_streamable(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id, [
            'status' => HistoricalSalesDocument::STATUS_SUPERSEDED,
        ]));
        $attachment = TenantContext::runFor($shop->id, fn () => $this->makeAttachment($shop->id, $document->id, $owner->id, "historical-attachments/{$shop->id}/superseded-doc.pdf"));

        $this->actingAs($owner)
            ->post(route('historical.documents.attachments.store', $document), ['file' => $this->fakeUpload()])
            ->assertRedirect();
        $this->assertSame(1, TenantContext::runFor($shop->id, fn () => HistoricalSalesDocumentAttachment::count()));

        $this->actingAs($owner)
            ->delete(route('historical.attachments.destroy', $attachment), ['reason' => 'Trying to retract'])
            ->assertRedirect();
        TenantContext::runFor($shop->id, function () use ($attachment): void {
            $this->assertTrue($attachment->fresh()->is_active);
        });

        $this->actingAs($owner)
            ->get(route('historical.attachments.show', $attachment))
            ->assertOk();
    }

    /**
     * Forces AccountingAuditService::log()'s AuditLog::create() to throw via a
     * model-event listener (no static-mocking fragility, and the listener is
     * scoped to this test's fresh application container). Proves the service's
     * DB::transaction() wrapping actually rolls back the attachment row AND
     * deletes the orphaned physical file on any post-write failure.
     */
    public function test_an_audit_log_failure_during_upload_rolls_back_the_row_and_deletes_the_orphaned_file(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        \App\Models\AuditLog::creating(function (): void {
            throw new \RuntimeException('Audit log intentionally failed for test.');
        });

        try {
            $this->actingAs($owner)
                ->post(route('historical.documents.attachments.store', $document), ['file' => $this->fakeUpload()])
                ->assertRedirect();
        } finally {
            \App\Models\AuditLog::flushEventListeners();
        }

        $this->assertSame(0, TenantContext::runFor($shop->id, fn () => HistoricalSalesDocumentAttachment::count()));
        Storage::disk('local')->assertDirectoryEmpty("historical-attachments/{$shop->id}");
    }

    public function test_an_audit_log_failure_during_removal_leaves_the_attachment_active_and_the_file_untouched(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        $this->actingAs($owner)->post(
            route('historical.documents.attachments.store', $document),
            ['file' => $this->fakeUpload()]
        );
        $attachment = TenantContext::runFor($shop->id, fn () => HistoricalSalesDocumentAttachment::first());
        $path = $attachment->file_path;

        \App\Models\AuditLog::creating(function (): void {
            throw new \RuntimeException('Audit log intentionally failed for test.');
        });

        try {
            $this->actingAs($owner)
                ->delete(route('historical.attachments.destroy', $attachment), ['reason' => 'Wrong bill'])
                ->assertRedirect();
        } finally {
            \App\Models\AuditLog::flushEventListeners();
        }

        TenantContext::runFor($shop->id, function () use ($attachment): void {
            $this->assertTrue($attachment->fresh()->is_active);
        });
        Storage::disk('local')->assertExists($path);
    }

    /**
     * Two in-memory copies both read the row while it was still active (the
     * concurrent-request shape); only the first `remove()` call may win. Proves
     * the model's atomic `whereNull('removed_at')` claim — not a prior
     * `is_active` read — is what decides the race, matching
     * `HistoricalDocumentLifecycleService::claimForPublishing()`'s pattern.
     */
    /**
     * Smoke-tests the Blade wiring on the document detail page itself, not
     * just the controller/service: the upload form and remove control must be
     * present on a draft document, and absent (read-only notice instead) once
     * the document is void — proving the `$lifecycle['is_void']` /
     * `is_superseded` flags actually reach and gate the attachments section.
     */
    public function test_document_screen_renders_upload_form_and_hides_it_when_read_only(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        $this->actingAs($owner)
            ->get(route('historical.documents.show', $document))
            ->assertOk()
            ->assertSee('Evidence attachments')
            ->assertSee('Upload evidence');

        $this->actingAs($owner)->post(
            route('historical.documents.attachments.store', $document),
            ['file' => $this->fakeUpload()]
        );

        $this->actingAs($owner)
            ->get(route('historical.documents.show', $document))
            ->assertOk()
            ->assertSee('bill-scan.pdf')
            ->assertSee('Remove');

        $voidDocument = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id, [
            'status' => HistoricalSalesDocument::STATUS_VOID,
            'void_reason' => 'Wrong customer entirely',
            'voided_at' => now(),
        ]));

        $this->actingAs($owner)
            ->get(route('historical.documents.show', $voidDocument))
            ->assertOk()
            ->assertSee('evidence is read-only')
            ->assertDontSee('Upload evidence');
    }

    public function test_concurrent_removal_requests_cannot_both_win_the_race(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        $this->actingAs($owner)->post(
            route('historical.documents.attachments.store', $document),
            ['file' => $this->fakeUpload()]
        );

        TenantContext::runFor($shop->id, function () use ($owner): void {
            $copyA = HistoricalSalesDocumentAttachment::first();
            $copyB = HistoricalSalesDocumentAttachment::find($copyA->id);

            $copyA->remove($owner->id, 'first request wins');

            $this->expectException(LogicException::class);
            $copyB->remove($owner->id, 'second request loses');
        });
    }

    /**
     * The removal CHECK says an inactive attachment must always name who removed
     * it. A `nullOnDelete` FK on `removed_by` contradicted that: hard deleting
     * the remover's user row would try to blank the very column the CHECK
     * forbids blanking, so the delete failed anyway — as an unreadable check
     * violation rather than a plain "this user is still referenced". RESTRICT
     * states the retention rule at the FK, where it belongs.
     *
     * Scope note: HARD deletion of a users row only. Deactivating a staff
     * account sets `users.is_active = false` and never touches this FK — the
     * closing assertion pins that, so nobody later "fixes" this constraint
     * believing it blocks ordinary offboarding.
     */
    public function test_removal_attribution_survives_an_attempt_to_hard_delete_the_remover(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        // Same role as the owner — roles are unique per (shop, name), and this
        // test is about the user row, not the role.
        $remover = $this->createOwnerUser($shop, Role::withoutTenant()->findOrFail($owner->role_id));
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        $this->actingAs($owner)->post(
            route('historical.documents.attachments.store', $document),
            ['file' => $this->fakeUpload()]
        );

        TenantContext::runFor($shop->id, function () use ($remover): void {
            HistoricalSalesDocumentAttachment::query()->firstOrFail()->remove($remover->id, 'wrong bill scanned');
        });

        // Nested DB::transaction() = SAVEPOINT. RefreshDatabase already holds an
        // outer transaction, and a raw FK violation would abort it outright
        // ("current transaction is aborted"), failing every later assertion.
        // The savepoint contains the damage so the row can still be inspected.
        try {
            DB::transaction(fn () => DB::table('users')->where('id', $remover->id)->delete());
            $this->fail('Hard deleting the remover must be refused — the removal audit trail names them.');
        } catch (QueryException $e) {
            // Must be a FOREIGN KEY violation (23503), not a CHECK violation
            // (23514). Under the old nullOnDelete the delete also failed — but
            // because SET NULL tripped the removal CHECK, which is an accident
            // of constraint ordering rather than a stated rule. Asserting only
            // "an error mentioning this table" cannot tell the two apart, and
            // that weaker assertion passed with the defect reinstated.
            $this->assertSame('23503', $e->getCode(), 'Refusal must come from the FK, not the removal CHECK.');
            $this->assertStringContainsString(
                'historical_sales_document_attachments_removed_by_foreign',
                $e->getMessage()
            );
        }

        TenantContext::runFor($shop->id, function () use ($remover): void {
            $attachment = HistoricalSalesDocumentAttachment::query()->firstOrFail();
            $this->assertFalse((bool) $attachment->is_active);
            $this->assertSame($remover->id, (int) $attachment->removed_by, 'The remover attribution was erased.');
            $this->assertSame('wrong bill scanned', $attachment->removed_reason);
        });

        // Ordinary offboarding is untouched by the RESTRICT. Via the model, not
        // the query builder — Postgres rejects the builder's integer 0 for a
        // boolean column, which is a binding quirk, not the behavior under test.
        $remover->forceFill(['is_active' => false])->save();
        $this->assertFalse((bool) $remover->fresh()->is_active, 'Deactivating the remover must still be allowed.');
    }

    // ════════════════════════════════════════════════════════════════
    // Document screen — the View affordance and the removal warning
    // ════════════════════════════════════════════════════════════════

    /**
     * The removal confirmation used to read "The file is deleted; the audit
     * record is kept." That is the opposite of what remove() does: it
     * deactivates the row and keeps BOTH the audit trail and the bytes on disk
     * (see the service docblock, and
     * test_removal_requires_a_reason_retains_the_file_and_keeps_the_audited_row
     * above, which proves the file survives). Telling an operator their
     * financial evidence was destroyed when it was not is the kind of untruth
     * that gets acted on — so the copy is pinned, not merely corrected.
     */
    public function test_the_removal_confirmation_does_not_claim_the_file_is_deleted(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        $this->actingAs($owner)->post(
            route('historical.documents.attachments.store', $document),
            ['file' => $this->fakeUpload()]
        );

        $response = $this->actingAs($owner)
            ->get(route('historical.documents.show', $document))
            ->assertOk();

        $response->assertDontSee('The file is deleted', false);
        $response->assertSee('The file and the audit history are both kept.', false);
    }

    /**
     * F2: the stream route 404s an inactive attachment
     * (HistoricalDocumentAttachmentController::show() aborts on !is_active), so
     * a View link rendered next to a removed row is a link to a 404.
     *
     * The gate is `is_active` ALONE and deliberately not the document
     * lifecycle. Void/superseded documents freeze their evidence but must keep
     * ACTIVE attachments viewable — that contract is already pinned at the
     * route level by test_void_document_attachments_are_read_only_but_still_streamable;
     * this covers the UI half of it.
     */
    public function test_view_link_is_hidden_for_a_removed_attachment_but_shown_for_an_active_one(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id));

        $this->actingAs($owner)->post(
            route('historical.documents.attachments.store', $document),
            ['file' => $this->fakeUpload()]
        );
        $attachment = TenantContext::runFor($shop->id, fn () => HistoricalSalesDocumentAttachment::query()->firstOrFail());
        $viewUrl = route('historical.attachments.show', $attachment);

        // Active: the link is there.
        $this->actingAs($owner)
            ->get(route('historical.documents.show', $document))
            ->assertOk()
            ->assertSee($viewUrl, false);

        $this->actingAs($owner)
            ->delete(route('historical.attachments.destroy', $attachment), ['reason' => 'wrong bill scanned'])
            ->assertRedirect();
        TenantContext::runFor($shop->id, function () use ($attachment): void {
            $this->assertFalse((bool) $attachment->fresh()->is_active);
        });

        // Removed: the row still shows (with its attribution), the link does not.
        $response = $this->actingAs($owner)
            ->get(route('historical.documents.show', $document))
            ->assertOk()
            ->assertSee('bill-scan.pdf')
            ->assertSee('wrong bill scanned');
        $response->assertDontSee($viewUrl, false);

        // And the link would indeed have been dead.
        $this->actingAs($owner)->get($viewUrl)->assertNotFound();
    }

    public function test_active_evidence_on_a_void_document_keeps_its_view_link(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, fn () => $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id, [
            'status' => HistoricalSalesDocument::STATUS_VOID,
            'void_reason' => 'Wrong customer entirely',
            'voided_at' => now(),
        ]));
        $attachment = TenantContext::runFor($shop->id, fn () => $this->makeAttachment($shop->id, $document->id, $owner->id, "historical-attachments/{$shop->id}/void-view-link.pdf"));

        $this->actingAs($owner)
            ->get(route('historical.documents.show', $document))
            ->assertOk()
            ->assertSee('evidence is read-only')
            // Frozen for WRITES, not for reading. Narrowing the View link to
            // is_active must not have narrowed it to the lifecycle as well.
            ->assertSee(route('historical.attachments.show', $attachment), false)
            ->assertDontSee('Upload evidence');
    }
}
