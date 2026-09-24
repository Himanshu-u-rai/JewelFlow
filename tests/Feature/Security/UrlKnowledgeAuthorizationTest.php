<?php

namespace Tests\Feature\Security;

use App\Models\KycDocument;
use App\Models\Permission;
use App\Models\Reporting\ReportExport;
use App\Models\Role;
use App\Models\User;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\Support\Reporting\StubReportService;
use Tests\TestCase;

/**
 * Matrix group V — URL handling.
 *
 * THE PROPERTY UNDER TEST
 * -----------------------
 * For a private resource, knowing the COMPLETE, CORRECT URL must grant nothing.
 * Every test here follows the same shape:
 *
 *   1. An authorised principal fetches the resource and we capture the exact URL
 *      that worked, plus the exact bytes it returned.
 *   2. That IDENTICAL url string is replayed by other principals.
 *   3. Each replay must be denied, and must disclose none of those bytes.
 *
 * Step 1 matters. Without it a denial could be passing because the URL was
 * malformed, the record did not exist, or the route was broken for everyone. The
 * captured-URL construction makes the authorised fetch the positive control for
 * every denial in the same test.
 *
 * THE FOUR PRINCIPALS (per the audit directive)
 *   - the authorised user            → 200 + bytes
 *   - another shop's owner           → denied, no bytes
 *   - a same-shop user lacking the permission → denied, no bytes
 *   - a logged-out visitor           → denied, no bytes
 *
 * WHAT THIS FAMILY DELIBERATELY DOES NOT DO
 * It adds no obfuscation. No identifier migration, no hashed or encoded ids, no
 * signature bolted onto an already-authenticated route. Those substitute secrecy
 * of the URL for authorization, which is the very thing these tests exist to
 * forbid. Where a signature IS present (queued report exports) the tests assert
 * it is NECESSARY BUT NOT SUFFICIENT — see the export section.
 *
 * RELATIONSHIP TO THE OTHER FAMILIES
 *   - group T (tenant isolation): the "another shop" principal here is the same
 *     boundary, reached through a URL rather than a listing.
 *   - group S (file storage): S3-01/S3-02 are about bytes that need NO URL
 *     knowledge check because nginx serves them before PHP runs. Group V only
 *     governs routes that actually reach PHP.
 *   - group U (intra-shop permissions): the third principal.
 *   - group I (infrastructure): the storage.local section pins a framework
 *     control this application silently depends on.
 */
class UrlKnowledgeAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        // Without a built manifest Vite throws while rendering the error layout,
        // so every 403/404 would arrive as a 500 and the status under test would
        // be invisible.
        $this->withoutVite();
    }

    /**
     * Issue a request the way production resolves tenant context.
     *
     * bootstrap/app.php:83-84 runs SubstituteBindings BEFORE EnsureTenantUser, so
     * binding scopes through BelongsToShop::resolveTenantShopId()'s Auth::check()
     * fallback — which is gated behind runningInConsole() and returns null under
     * PHPUnit. Unpinned, every bind fail-closes to 404 and every denial below
     * would pass for the wrong reason. ProductionTenantResolutionTest covers the
     * real resolution branch separately; this file is about URL knowledge.
     */
    private function requestAs(User $user, string $url)
    {
        $this->actingAs($user);

        return TenantContext::runFor((int) $user->shop_id, fn () => $this->get($url));
    }

    /** A same-shop user on a separate role holding exactly $permissions. */
    private function staffUser(int $shopId, array $permissions): User
    {
        // shop_id is not fillable on Role — set it explicitly.
        $role = new Role();
        $role->forceFill([
            'shop_id' => $shopId,
            'name' => 'staff_'.uniqid(),
            'display_name' => 'Staff',
        ])->save();
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create([
            'shop_id' => $shopId,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /** Assert a response is a denial that leaked none of the protected bytes. */
    private function assertDeniedWithoutBytes($response, string $sentinel, string $who): void
    {
        $this->assertNotEquals(200, $response->status(),
            "{$who} must not receive the resource; got ".$response->status());
        $this->assertStringNotContainsString($sentinel, $response->getContent(),
            "{$who} received protected bytes in the response body");
    }

    // ================================================================== KYC ==
    // Identity documents (Aadhaar, PAN, passport). The highest-sensitivity
    // private download in the application.

    private function kycDocument(int $shopId, int $customerId, int $userId, string $path): KycDocument
    {
        return KycDocument::create([
            'shop_id' => $shopId,
            'customer_id' => $customerId,
            'uploaded_by' => $userId,
            'document_type' => KycDocument::TYPE_AADHAAR,
            'file_path' => $path,
            'file_disk' => 'local',
            'original_filename' => 'aadhaar.jpg',
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => 32,
        ]);
    }

    /**
     * V-kyc-url-knowledge — the whole family in one test. One URL, four
     * principals, captured from a real authorised success.
     *
     * Production change that would break this: dropping the shop_id comparison at
     * KycDocumentController::show():50, or removing can:customers.view from the
     * route, or removing the auth middleware.
     */
    public function test_knowing_a_kyc_document_url_grants_nothing_to_the_other_three_principals(): void
    {
        Storage::fake('local');
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB] = $this->createRetailerTenant();

        $customerA = $this->createCustomer($shopA->id);
        $path = "kyc/{$shopA->id}/url-knowledge.jpg";
        Storage::disk('local')->put($path, 'KYC_AADHAAR_SENTINEL_4d2');
        $doc = $this->kycDocument($shopA->id, $customerA->id, $ownerA->id, $path);

        // 1. Authorised principal — capture the URL that actually works.
        $url = route('kyc-documents.show', $doc);
        $authorised = $this->requestAs($ownerA, $url);
        $authorised->assertOk();
        $this->assertSame('KYC_AADHAAR_SENTINEL_4d2', $authorised->streamedContent(),
            'the positive control must really return the protected bytes');

        // 2. Another shop's owner replays the identical URL.
        $this->assertDeniedWithoutBytes(
            $this->requestAs($ownerB, $url), 'KYC_AADHAAR_SENTINEL_4d2', 'a foreign shop owner');

        // 3. A same-shop user without customers.view replays it.
        $staff = $this->staffUser($shopA->id, ['karigar.view']);
        $this->assertDeniedWithoutBytes(
            $this->requestAs($staff, $url), 'KYC_AADHAAR_SENTINEL_4d2', 'a same-shop user lacking customers.view');

        // 4. A logged-out visitor replays it.
        app('auth')->forgetGuards();
        $this->assertDeniedWithoutBytes(
            $this->get($url), 'KYC_AADHAAR_SENTINEL_4d2', 'a logged-out visitor');
    }

    /**
     * V-kyc-controller-ownership — isolates the SECOND layer.
     *
     * Found by mutation, not by design: deleting the shop_id comparison at
     * KycDocumentController::show():50 left the test above still passing. Its
     * foreign-shop denial comes entirely from the BelongsToShop global scope
     * fail-closing the route-model BIND, so the controller's own check never had
     * to work. Defence in depth that nothing exercises is decoration, and the
     * ledger would have recorded coverage this suite did not have.
     *
     * This test neutralises the outer layer to reach the inner one: tenant
     * context is pinned to shop A so the bind SUCCEEDS, while the authenticated
     * principal is owner B. The controller check is then the only thing standing
     * between B and the bytes.
     *
     * The combination is deliberately artificial — EnsureTenantUser derives
     * context from the authenticated user, so context and principal cannot
     * diverge this way in production. That is precisely why the check is called
     * defence in depth, and precisely why it needs its own test: it only ever
     * runs when something upstream has already failed, which is when you least
     * want it to be untested. It becomes the sole control the moment any query
     * reaches this model through withoutTenant() or a raw builder.
     */
    public function test_the_kyc_controller_refuses_a_foreign_user_even_when_the_scope_lets_the_model_resolve(): void
    {
        Storage::fake('local');
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB] = $this->createRetailerTenant();

        $customerA = $this->createCustomer($shopA->id);
        $path = "kyc/{$shopA->id}/scope-bypassed.jpg";
        Storage::disk('local')->put($path, 'KYC_AADHAAR_SENTINEL_4d2');
        $doc = $this->kycDocument($shopA->id, $customerA->id, $ownerA->id, $path);

        $url = route('kyc-documents.show', $doc);

        // Owner B authenticated, but context pinned to A so the bind resolves.
        $this->actingAs($ownerB);
        $response = TenantContext::runFor($shopA->id, fn () => $this->get($url));

        $this->assertDeniedWithoutBytes($response, 'KYC_AADHAAR_SENTINEL_4d2',
            'a foreign user whose bind was allowed to resolve');

        // Positive control: the same construction with the RIGHTFUL owner returns
        // the bytes, so the denial above is the ownership check and not the
        // fixture, the disk or the route.
        $this->actingAs($ownerA);
        $allowed = TenantContext::runFor($shopA->id, fn () => $this->get($url));
        $allowed->assertOk();
        $this->assertSame('KYC_AADHAAR_SENTINEL_4d2', $allowed->streamedContent());
    }

    /**
     * V-kyc-url-shape — the URL itself must not be the disclosure. A path that
     * embedded the document type, the customer name or the stored filename would
     * leak to referrers, proxy logs, analytics and browser history even when the
     * request is correctly refused.
     */
    public function test_a_kyc_url_carries_no_sensitive_data_in_its_path_or_query_string(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $doc = $this->kycDocument($shop->id, $customer->id, $owner->id, "kyc/{$shop->id}/aadhaar-scan.jpg");

        $url = route('kyc-documents.show', $doc);

        $this->assertStringNotContainsString('?', $url,
            'a private document URL must not carry a query string at all');
        foreach (['aadhaar', 'Aadhaar', 'aadhaar-scan', $customer->name] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $url,
                'the URL must not disclose document content or subject identity');
        }
    }

    // ============================================================== EXPORTS ==
    // Queued report exports are the one private download that DOES carry a
    // signature, because the link is delivered by notification. The signature is
    // there to bind the link to a specific export, NOT to replace authorization.

    private function makeExport(int $shopId, array $overrides = []): ReportExport
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $overrides) {
            $export = ReportExport::create(array_merge([
                'shop_id' => $shopId, 'user_id' => null,
                'report_key' => StubReportService::KEY, 'report_version' => 'stub-report@1',
                'profile' => 'detailed', 'format' => 'csv', 'filters' => [],
                'sensitive_included' => false, 'mode' => 'queued', 'status' => 'done',
                'row_count' => 1, 'file_disk' => 'local', 'file_path' => null,
                'expires_at' => now()->addDays(7), 'generated_at' => now(),
            ], $overrides));

            // Stored where the queued job writes it: inside the export's own
            // directory (S3-18). A flat path is refused at download.
            if (! array_key_exists('file_path', $overrides)) {
                $path = $export->storageDirectory().'/'.uniqid('exp_').'.csv';
                Storage::disk('local')->put($path, "col\nEXPORT_SENTINEL_9c1");
                $export->update(['file_path' => $path]);
            }

            return $export;
        });
    }

    private function grant(User $user, array $names): void
    {
        $ids = Permission::whereIn('name', $names)->pluck('id');
        TenantContext::runFor((int) $user->shop_id, function () use ($user, $ids) {
            $user->role->permissions()->syncWithoutDetaching($ids);
        });
        $user->unsetRelation('role');
    }

    /**
     * V-export-url-knowledge — a VALID, UNEXPIRED signed link replayed by the
     * other three principals. This is the test that proves the signature is not
     * doing the authorization.
     *
     * ExportDownloadAuthzTest already covers the authorised owner, the foreign
     * tenant and the unsigned request. It does NOT cover a logged-out holder of a
     * valid link, nor a same-shop user without the report permission. Those two
     * gaps are the point of this test.
     *
     * Production change that would break this: removing `auth` from the route and
     * letting `signed` stand alone, or deleting the permission re-check at
     * ExportDownloadController::download():43.
     */
    public function test_a_valid_signed_export_link_grants_nothing_to_the_other_three_principals(): void
    {
        Storage::fake('local');
        app(ReportRegistry::class)->register(StubReportService::KEY, StubReportService::class);

        [$ownerA, $shopA] = $this->createManufacturerTenant();
        [$ownerB, $shopB] = $this->createManufacturerTenant();
        $this->grant($ownerA, ['reports.view', 'reports.export']);
        $this->grant($ownerB, ['reports.view', 'reports.export']);

        $export = $this->makeExport($shopA->id);
        $url = URL::temporarySignedRoute('reporting.exports.download', now()->addHour(), ['export' => $export->id]);

        // 1. Authorised principal — the link genuinely works for its owner.
        $authorised = $this->requestAs($ownerA, $url);
        $authorised->assertOk();
        $this->assertStringContainsString('EXPORT_SENTINEL_9c1', $authorised->streamedContent(),
            'the positive control must really return the export bytes');

        // 2. Another shop's owner, holding the very same valid signature.
        $this->assertDeniedWithoutBytes(
            $this->requestAs($ownerB, $url), 'EXPORT_SENTINEL_9c1', 'a foreign tenant with a valid signature');

        // 3. A same-shop user without any reports permission.
        $staff = $this->staffUser($shopA->id, ['customers.view']);
        $this->assertDeniedWithoutBytes(
            $this->requestAs($staff, $url), 'EXPORT_SENTINEL_9c1', 'a same-shop user lacking reports.view');

        // 4. A logged-out holder of the link.
        //
        // ATTRIBUTION CAVEAT — established by mutation, recorded rather than
        // glossed. Removing Authenticate from this route leaves the assertion
        // below still passing, with a 404. The denial in THIS process therefore
        // comes from the route-model bind fail-closing (BelongsToShop resolves a
        // null tenant for a guest), not from the auth middleware. Under PHPUnit
        // that is further confounded with the runningInConsole() artifact of
        // §M-01, so this test cannot distinguish the production scope fail-close
        // from the harness one.
        //
        // What IS established, structurally rather than behaviourally: the
        // router reports Authenticate at position 2 and ValidateSignature last
        // for this route, so a production guest is refused by auth before the
        // signature is ever evaluated. Grade this assertion as "denial verified,
        // attribution structural" — not as proof that auth fired.
        app('auth')->forgetGuards();
        $this->assertDeniedWithoutBytes(
            $this->get($url), 'EXPORT_SENTINEL_9c1', 'a logged-out holder of a valid signed link');
    }

    /**
     * V-export-signature-expiry — the SIGNATURE's own expiry, which is a distinct
     * control from the export record's expires_at column.
     *
     * ExportDownloadAuthzTest::test_expired_export_returns_410 exercises the
     * record expiry (a controller check). This exercises the link expiry (a
     * middleware check) — a link that has aged out must fail even when the record
     * behind it is perfectly live.
     */
    public function test_an_expired_signature_is_refused_even_for_the_authorised_owner(): void
    {
        Storage::fake('local');
        app(ReportRegistry::class)->register(StubReportService::KEY, StubReportService::class);

        [$owner, $shop] = $this->createManufacturerTenant();
        $this->grant($owner, ['reports.view', 'reports.export']);
        $export = $this->makeExport($shop->id); // record itself expires in 7 days

        $expired = URL::temporarySignedRoute('reporting.exports.download', now()->subMinute(), ['export' => $export->id]);

        $this->assertDeniedWithoutBytes(
            $this->requestAs($owner, $expired), 'EXPORT_SENTINEL_9c1', 'the owner using an aged-out link');

        // Positive control: the identical owner, record and route with a FRESH
        // signature succeeds — so the denial above is the expiry, not a broken
        // fixture.
        $fresh = URL::temporarySignedRoute('reporting.exports.download', now()->addHour(), ['export' => $export->id]);
        $this->requestAs($owner, $fresh)->assertOk();
    }

    /**
     * V-export-signature-tamper — flipping a byte of the signature must fail
     * closed. Guards against a future change that parses the query string
     * loosely or compares signatures non-strictly.
     */
    public function test_a_tampered_signature_is_refused(): void
    {
        Storage::fake('local');
        app(ReportRegistry::class)->register(StubReportService::KEY, StubReportService::class);

        [$owner, $shop] = $this->createManufacturerTenant();
        $this->grant($owner, ['reports.view', 'reports.export']);
        $export = $this->makeExport($shop->id);

        $url = URL::temporarySignedRoute('reporting.exports.download', now()->addHour(), ['export' => $export->id]);
        $tampered = preg_replace('/signature=([0-9a-f])/', 'signature='.'0', $url, 1);
        $this->assertNotSame($url, $tampered, 'the fixture must actually alter the signature');

        $this->assertDeniedWithoutBytes(
            $this->requestAs($owner, $tampered), 'EXPORT_SENTINEL_9c1', 'a holder of a tampered link');
    }

    // ======================================================== storage.serve ==
    // config/filesystems.php sets 'serve' => true on the PRIVATE local disk, so
    // Laravel registers GET /storage/{path} (route name storage.local) with NO
    // route middleware. The control is inside the framework's invokable
    // ServeFile controller, which requires a valid relative signature for any
    // disk whose visibility is not 'public'.
    //
    // That is a framework behaviour this application silently depends on to keep
    // relocated KYC and karigar-invoice bytes unreachable. An upgrade that
    // changed it would re-expose exactly the files S3-01/S3-02 moved, and
    // route:list would still show an empty middleware column. These tests pin it.

    /** V-serve-unsigned — the private disk must not answer an unsigned request. */
    public function test_the_private_disk_serve_route_refuses_an_unsigned_request(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('karigar-invoices/1/relocated.pdf', 'RELOCATED_PRIVATE_SENTINEL');

        $response = $this->get('/storage/karigar-invoices/1/relocated.pdf');

        $this->assertDeniedWithoutBytes($response, 'RELOCATED_PRIVATE_SENTINEL',
            'an unsigned caller of the private serve route');
    }

    /** V-serve-guest-signed — and a signature alone must not cross tenants. */
    public function test_the_private_disk_serve_route_refuses_a_traversal_path(): void
    {
        Storage::fake('local');

        $signed = URL::signedRoute('storage.local', ['path' => '../../../.env'], null, false);
        $response = $this->get($signed);

        $this->assertNotEquals(200, $response->status(),
            'a traversal path must not resolve; got '.$response->status());
        $this->assertStringNotContainsString('APP_KEY', $response->getContent(),
            'the environment file must never be reachable through the serve route');
    }
}
