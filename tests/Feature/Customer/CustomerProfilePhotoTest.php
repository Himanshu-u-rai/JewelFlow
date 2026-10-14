<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\CustomerProfilePhoto;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Invoice;
use App\Models\KycDocument;
use App\Models\Shop;
use App\Models\User;
use App\Services\CustomerProfilePhotoService;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\Support\ProfilePhotoFixtures;
use Tests\TestCase;

class CustomerProfilePhotoTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        // Console route binding needs explicit context on EVERY request; middleware clears it.
        $shopId = auth()->user()?->shop_id;
        $request = fn () => parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        return $shopId ? TenantContext::runFor($shopId, $request) : $request();
    }

    private function tenant(bool $dhiran = false): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        if ($dhiran) {
            $shop->forceFill(['shop_type' => 'dhiran'])->save();
            $shop->editions()->delete();
            $shop->editions()->create(['edition' => 'dhiran']);
            $user->forceFill(['realm' => 'dhiran', 'email_verified_at' => now()])->save();
            DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        }
        $customer = $this->createCustomer($shop->id, ['first_name' => 'Asha', 'last_name' => 'Example', 'pan' => 'ABCDE1234F']);
        TenantContext::set($shop->id);

        return [$user, $shop, $customer];
    }

    private function url(Customer $customer, bool $dhiran = false): string
    {
        return ($dhiran ? 'https://dhiran.jewelflows.com/dhiran/borrowers/' : 'https://jewelflows.com/customers/').$customer->id.'/photo';
    }

    private function upload(User $user, Customer $customer, string $version = '', bool $dhiran = false)
    {
        return TenantContext::runFor($customer->shop_id, fn () => $this->actingAs($user)->postJson($this->url($customer, $dhiran), [
            'customer_photo' => ProfilePhotoFixtures::portrait(), 'customer_photo_version' => $version,
        ]));
    }

    private function row(Customer $customer): CustomerProfilePhoto
    {
        return CustomerProfilePhoto::withoutTenant()->where('customer_id', $customer->id)->firstOrFail();
    }

    public function test_upload_stores_only_one_private_optimized_file_and_no_customer_mutation(): void
    {
        [$user, , $customer] = $this->tenant();
        $before = $customer->fresh()->getAttributes();
        $response = $this->upload($user, $customer)->assertOk()->assertJsonStructure(['photo' => ['url', 'version']]);
        $photo = $this->row($customer);
        $disk = Storage::disk('local');
        $this->assertSame([$photo->file_path], $disk->allFiles('customer-profile-photos'));
        $this->assertSame('image/webp', getimagesizefromstring($disk->get($photo->file_path))['mime']);
        $this->assertLessThanOrEqual(100 * 1024, $photo->size_bytes);
        $this->assertSame($before, $customer->fresh()->getAttributes());
        $this->assertStringNotContainsString('/storage/', $response->getContent());
        $this->assertStringNotContainsString($photo->file_path, $response->getContent());
        $this->assertArrayNotHasKey('file_path', $photo->toArray());
        $customer->load('profilePhoto');
        $this->assertArrayNotHasKey('profile_photo', $customer->toArray());
        $image = $this->actingAs($user)->get($response->json('photo.url'))->assertOk();
        $image->assertHeader('Content-Type', 'image/webp');
        $this->assertStringContainsString('no-store', $image->headers->get('Cache-Control'));
    }

    public function test_replacement_commits_new_photo_and_cleans_the_old_file(): void
    {
        [$user, , $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        $old = $this->row($customer)->file_path;
        $next = $this->upload($user, $customer, $first->json('photo.version'))->assertOk();
        $this->assertNotSame($first->json('photo.version'), $next->json('photo.version'));
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($this->row($customer)->file_path);
        $this->actingAs($user)->get($first->json('photo.url'))->assertNotFound();
    }

    public function test_removal_returns_the_initials_state_and_cleans_file(): void
    {
        [$user, , $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        $old = $this->row($customer)->file_path;
        $this->actingAs($user)->deleteJson($this->url($customer), ['customer_photo_version' => $first->json('photo.version')])->assertOk()->assertJsonPath('photo', null);
        $this->assertDatabaseMissing('customer_profile_photos', ['customer_id' => $customer->id]);
        Storage::disk('local')->assertMissing($old);
        $this->actingAs($user)->get($first->json('photo.url'))->assertNotFound();
    }

    public function test_stale_replace_and_delete_cannot_remove_the_winning_photo(): void
    {
        [$user, , $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        $winner = $this->upload($user, $customer, $first->json('photo.version'))->assertOk();
        $current = $this->row($customer)->file_path;
        $this->upload($user, $customer, $first->json('photo.version'))->assertStatus(409);
        $this->actingAs($user)->deleteJson($this->url($customer), ['customer_photo_version' => $first->json('photo.version')])->assertStatus(409);
        $this->assertSame([$current], Storage::disk('local')->allFiles('customer-profile-photos'));
        $this->actingAs($user)->get($winner->json('photo.url'))->assertOk();
    }

    public function test_missing_revision_is_not_a_blind_replacement_or_delete(): void
    {
        [$user, , $customer] = $this->tenant();
        $this->upload($user, $customer)->assertOk();
        $this->actingAs($user)->postJson($this->url($customer), ['customer_photo' => ProfilePhotoFixtures::portrait()])->assertUnprocessable();
        $this->actingAs($user)->deleteJson($this->url($customer))->assertUnprocessable();
        $this->upload($user, $customer)->assertStatus(409);
        $this->assertCount(1, Storage::disk('local')->allFiles('customer-profile-photos'));
    }

    public function test_view_only_can_fetch_but_not_upload_or_remove(): void
    {
        [$user, $shop, $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        $this->grantOnlyPermissions($user, ['customers.view']);
        $user = $user->fresh();
        $this->actingAs($user)->get($first->json('photo.url'))->assertOk();
        $this->upload($user, $customer, $first->json('photo.version'))->assertForbidden();
        $this->actingAs($user)->deleteJson($this->url($customer), ['customer_photo_version' => $first->json('photo.version')])->assertForbidden();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->get('https://jewelflows.com/customers/'.$customer->id));
        $html->assertOk()->assertDontSee('data-photo-action="camera"', false)->assertDontSee('data-photo-action="remove"', false);
    }

    public function test_no_view_permission_and_guests_cannot_fetch_direct_images(): void
    {
        [$user, , $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        $this->grantOnlyPermissions($user, ['customers.edit']);
        $this->actingAs($user->fresh())->get($first->json('photo.url'))->assertForbidden();
        auth()->forgetGuards();
        $this->getJson($first->json('photo.url'))->assertUnauthorized();
    }

    public function test_cross_shop_direct_image_replace_and_delete_are_denied(): void
    {
        [$ownerA, $shopA] = $this->tenant();
        [$ownerB, , $customerB] = $this->tenant();
        $first = $this->upload($ownerB, $customerB)->assertOk();
        TenantContext::runFor($shopA->id, function () use ($ownerA, $customerB, $first) {
            $this->actingAs($ownerA)->get($first->json('photo.url'))->assertNotFound();
            $this->actingAs($ownerA)->postJson($this->url($customerB), ['customer_photo' => ProfilePhotoFixtures::portrait(), 'customer_photo_version' => $first->json('photo.version')])->assertNotFound();
            $this->actingAs($ownerA)->deleteJson($this->url($customerB), ['customer_photo_version' => $first->json('photo.version')])->assertNotFound();
        });
        Storage::disk('local')->assertExists($this->row($customerB)->file_path);
    }

    public function test_dhiran_uses_its_own_routes_and_mutation_permissions(): void
    {
        [$user, , $customer] = $this->tenant(true);
        $first = $this->upload($user, $customer, '', true)->assertOk();
        $this->actingAs($user)->get($first->json('photo.url'))->assertOk();
        $this->assertStringContainsString('/dhiran/borrowers/', $first->json('photo.url'));
        $this->grantOnlyPermissions($user, ['dhiran.view']);
        $user = $user->fresh();
        $this->actingAs($user)->get($first->json('photo.url'))->assertOk();
        $this->upload($user, $customer, $first->json('photo.version'), true)->assertForbidden();
        $this->actingAs($user)->deleteJson($this->url($customer, true), ['customer_photo_version' => $first->json('photo.version')])->assertForbidden();
    }

    public function test_dhiran_cannot_use_retail_routes_even_if_an_edition_is_forged(): void
    {
        [$user, $shop, $customer] = $this->tenant(true);
        $shop->editions()->create(['edition' => 'retailer']);
        $this->actingAs($user)->get($this->url($customer))->assertRedirect();
        $this->upload($user, $customer)->assertRedirect();
    }

    public function test_erp_cannot_use_dhiran_photo_routes(): void
    {
        [$user, $shop, $customer] = $this->tenant();
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $this->upload($user, $customer, '', true)->assertRedirect();
        $this->assertSame([], Storage::disk('local')->allFiles('customer-profile-photos'));
    }

    public function test_photo_routes_do_not_bypass_enforced_subscription_recovery(): void
    {
        [$user, $shop, $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        config(['platform.enforce_subscriptions' => true]);
        $subscription = $shop->subscriptions()->latest('id')->firstOrFail();
        $subscription->forceFill(['status' => 'expired', 'ends_at' => now()->subDays(10)->toDateString(), 'grace_ends_at' => now()->subDays(5)->toDateString()])->save();
        $this->actingAs($user)->getJson($first->json('photo.url'))->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
        $this->upload($user, $customer, $first->json('photo.version'))->assertForbidden();
        Storage::disk('local')->assertExists($this->row($customer)->file_path);
    }

    public function test_dhiran_photo_routes_retain_email_and_edition_gates(): void
    {
        [$user, $shop, $customer] = $this->tenant(true);
        $first = $this->upload($user, $customer, '', true)->assertOk();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user->fresh())->getJson($first->json('photo.url'))->assertStatus(409)->assertJsonPath('message', 'Verify your email to continue.');
        $user->forceFill(['email_verified_at' => now()])->save();
        $shop->editions()->delete();
        $this->actingAs($user->fresh())->get($first->json('photo.url'))->assertRedirect();
        Storage::disk('local')->assertExists($this->row($customer)->file_path);
    }

    public function test_administrative_read_only_blocks_mutations_but_not_photo_views(): void
    {
        [$user, $shop, $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        $admin = $this->createPlatformAdmin();
        $shop->forceFill(['access_mode' => 'read_only', 'suspended_by' => $admin->id])->save();
        $user->unsetRelation('shop');
        $this->upload($user, $customer, $first->json('photo.version'))->assertForbidden();
        $this->actingAs($user)->get($first->json('photo.url'))->assertOk();
    }

    public function test_archived_customer_keeps_historical_view_and_existing_profile_edit_rules(): void
    {
        [$user, , $customer] = $this->tenant();
        $customer->forceFill(['is_active' => false])->save();
        $first = $this->upload($user, $customer)->assertOk();
        $this->actingAs($user)->get($first->json('photo.url'))->assertOk();
        $this->assertTrue($customer->fresh()->isArchived());
    }

    public function test_invalid_photo_preserves_existing_photo_and_no_extra_file(): void
    {
        [$user, , $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        $old = $this->row($customer)->file_path;
        $this->actingAs($user)->postJson($this->url($customer), [
            'customer_photo' => ProfilePhotoFixtures::fromBytes('<svg/>', 'bad.jpg'),
            'customer_photo_version' => $first->json('photo.version'),
        ])->assertUnprocessable()->assertJsonValidationErrors('customer_photo');
        $this->assertSame([$old], Storage::disk('local')->allFiles('customer-profile-photos'));
        $this->assertSame($old, $this->row($customer)->file_path);
    }

    public function test_truncated_jpeg_is_refused_and_the_current_photo_is_kept(): void
    {
        [$user, , $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        $before = $this->row($customer)->only(['file_path', 'version', 'width', 'height', 'size_bytes']);
        $jpeg = ProfilePhotoFixtures::jpegBytes();
        $this->actingAs($user)->postJson($this->url($customer), [
            'customer_photo' => ProfilePhotoFixtures::fromBytes(substr($jpeg, 0, intdiv(strlen($jpeg), 3)), 'cut.jpg'),
            'customer_photo_version' => $first->json('photo.version'),
        ])->assertUnprocessable()->assertJsonPath('errors.customer_photo.0', fn (string $m) => str_contains($m, 'incomplete or damaged'));
        $this->assertSame($before, $this->row($customer)->only(['file_path', 'version', 'width', 'height', 'size_bytes']));
        $this->assertSame([$before['file_path']], Storage::disk('local')->allFiles('customer-profile-photos'));
        $this->actingAs($user)->get($first->json('photo.url'))->assertOk();
    }

    public function test_false_storage_write_preserves_existing_photo(): void
    {
        [$user, , $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        $disk = Storage::disk('local');
        $failing = Mockery::mock($disk);
        $failing->shouldReceive('put')->andReturn(false);
        Storage::set('local', $failing);
        $this->upload($user, $customer, $first->json('photo.version'))->assertUnprocessable();
        Storage::set('local', $disk);
        $this->assertSame([$this->row($customer)->file_path], $disk->allFiles('customer-profile-photos'));
    }

    public function test_thrown_storage_write_preserves_existing_photo(): void
    {
        [$user, , $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        $disk = Storage::disk('local');
        $failing = Mockery::mock($disk);
        $failing->shouldReceive('put')->andThrow(new \RuntimeException('Synthetic disk failure'));
        Storage::set('local', $failing);
        $this->upload($user, $customer, $first->json('photo.version'))->assertUnprocessable();
        Storage::set('local', $disk);
        $this->assertSame([$this->row($customer)->file_path], $disk->allFiles('customer-profile-photos'));
    }

    public function test_database_failure_rolls_back_reference_and_cleans_candidate(): void
    {
        [$user, , $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        $old = $this->row($customer)->file_path;
        Event::listen('eloquent.saving: '.CustomerProfilePhoto::class, fn () => throw new \RuntimeException('Synthetic database save failure'));
        $this->upload($user, $customer, $first->json('photo.version'))->assertUnprocessable();
        $this->assertSame($old, $this->row($customer)->file_path);
        $this->assertSame([$old], Storage::disk('local')->allFiles('customer-profile-photos'));
    }

    public function test_cleanup_will_not_delete_a_currently_selected_photo(): void
    {
        [$user, , $customer] = $this->tenant();
        $this->upload($user, $customer)->assertOk();
        $current = $this->row($customer)->file_path;
        $this->assertFalse(app(CustomerProfilePhotoService::class)->cleanup($current));
        Storage::disk('local')->assertExists($current);
    }

    public function test_database_removal_failure_keeps_the_existing_file_and_reference(): void
    {
        [$user, , $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        $old = $this->row($customer)->file_path;
        Event::listen('eloquent.deleting: '.CustomerProfilePhoto::class, fn () => throw new \RuntimeException('Synthetic deletion failure'));
        $this->actingAs($user)->deleteJson($this->url($customer), ['customer_photo_version' => $first->json('photo.version')])->assertUnprocessable();
        $this->assertSame($old, $this->row($customer)->file_path);
        Storage::disk('local')->assertExists($old);
    }

    public function test_cleanup_failure_defers_only_the_superseded_file_and_can_be_retried(): void
    {
        [$user, , $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        $old = $this->row($customer)->file_path;
        $disk = Storage::disk('local');
        $failing = Mockery::mock($disk);
        $failing->shouldReceive('delete')->with($old)->andThrow(new \RuntimeException('Synthetic cleanup failure'));
        Storage::set('local', $failing);
        $next = $this->upload($user, $customer, $first->json('photo.version'))->assertOk();
        Storage::set('local', $disk);
        $current = $this->row($customer)->file_path;
        $disk->assertExists([$old, $current]);
        $this->actingAs($user)->get($next->json('photo.url'))->assertOk();
        $this->assertTrue(app(CustomerProfilePhotoService::class)->cleanup($old));
        $disk->assertMissing($old);
        $disk->assertExists($current);
        $this->assertFalse(app(CustomerProfilePhotoService::class)->cleanup('kyc/keep.jpg'));
        $this->assertFalse(app(CustomerProfilePhotoService::class)->cleanup('customer-profile-photos/1/../kyc/keep.jpg'));
    }

    public function test_full_create_storage_failure_does_not_leave_a_partial_customer(): void
    {
        [$user, $shop] = $this->tenant();
        $disk = Storage::disk('local');
        $failing = Mockery::mock($disk);
        $failing->shouldReceive('put')->andReturn(false);
        Storage::set('local', $failing);
        $this->actingAs($user)->post('https://jewelflows.com/customers', [
            'first_name' => 'FailedPhoto', 'last_name' => 'Example', 'customer_photo' => ProfilePhotoFixtures::portrait(),
        ])->assertRedirect()->assertSessionHasErrors('customer_photo')->assertSessionHasInput('first_name', 'FailedPhoto');
        Storage::set('local', $disk);
        $this->assertDatabaseMissing('customers', ['shop_id' => $shop->id, 'first_name' => 'FailedPhoto']);
        $this->assertSame([], $disk->allFiles('customer-profile-photos'));
    }

    public function test_full_edit_replaces_and_removes_photo_with_the_validated_details(): void
    {
        [$user, , $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        $old = $this->row($customer)->file_path;
        $this->actingAs($user)->put('https://jewelflows.com/customers/'.$customer->id, [
            'first_name' => 'Updated', 'last_name' => 'Example', 'customer_photo' => ProfilePhotoFixtures::portrait(),
            'customer_photo_version' => $first->json('photo.version'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Updated', $customer->fresh()->first_name);
        Storage::disk('local')->assertMissing($old);
        $current = $this->row($customer);
        $this->actingAs($user)->put('https://jewelflows.com/customers/'.$customer->id, [
            'first_name' => 'Updated', 'last_name' => 'Example', 'customer_photo_remove' => '1',
            'customer_photo_version' => $current->version,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('customer_profile_photos', ['customer_id' => $customer->id]);
        Storage::disk('local')->assertMissing($current->file_path);
    }

    public function test_dhiran_cross_shop_direct_reads_and_mutations_are_denied(): void
    {
        [$ownerA, $shopA] = $this->tenant(true);
        [$ownerB, , $customerB] = $this->tenant(true);
        $photo = $this->upload($ownerB, $customerB, '', true)->assertOk();
        TenantContext::runFor($shopA->id, function () use ($ownerA, $customerB, $photo) {
            $this->actingAs($ownerA)->get($photo->json('photo.url'))->assertNotFound();
            $this->actingAs($ownerA)->getJson($this->url($customerB, true))->assertNotFound();
            $this->actingAs($ownerA)->postJson($this->url($customerB, true), [
                'customer_photo' => ProfilePhotoFixtures::portrait(), 'customer_photo_version' => $photo->json('photo.version'),
            ])->assertNotFound();
            $this->actingAs($ownerA)->deleteJson($this->url($customerB, true), ['customer_photo_version' => $photo->json('photo.version')])->assertNotFound();
        });
        Storage::disk('local')->assertExists($this->row($customerB)->file_path);
    }

    public function test_prune_dry_run_and_real_run_only_remove_old_unreferenced_feature_files(): void
    {
        [$user, , $customer] = $this->tenant();
        $this->upload($user, $customer)->assertOk();
        $disk = Storage::disk('local');
        $selected = $this->row($customer)->file_path;
        $orphan = 'customer-profile-photos/'.$customer->shop_id.'/'.$customer->id.'/'.(string) \Illuminate\Support\Str::uuid().'.webp';
        $recent = 'customer-profile-photos/'.$customer->shop_id.'/'.$customer->id.'/'.(string) \Illuminate\Support\Str::uuid().'.webp';
        $disk->put($orphan, $disk->get($selected));
        $disk->put($recent, $disk->get($selected));
        $disk->put('kyc/not-a-photo.jpg', 'private evidence');
        touch($disk->path($orphan), now()->subDays(2)->timestamp);
        touch($disk->path($selected), now()->subDays(2)->timestamp);
        $this->artisan('customer-photos:prune', ['--dry-run' => true])->assertSuccessful();
        $disk->assertExists($orphan);
        $this->artisan('customer-photos:prune')->assertSuccessful();
        $disk->assertMissing($orphan);
        $disk->assertExists([$selected, $recent, 'kyc/not-a-photo.jpg']);
    }

    public function test_full_create_accepts_optional_photo_and_quick_add_remains_photo_free(): void
    {
        [$user, $shop] = $this->tenant();
        $this->actingAs($user)->post('https://jewelflows.com/customers', [
            'first_name' => 'Photo', 'last_name' => 'Example', 'customer_photo' => ProfilePhotoFixtures::portrait(),
        ])->assertRedirect();
        $created = Customer::withoutTenant()->where('shop_id', $shop->id)->where('first_name', 'Photo')->firstOrFail();
        $this->assertNotNull($this->row($created));
        $quick = $this->actingAs($user)->postJson('https://jewelflows.com/customers/quick-store', ['name' => 'Quick Example'])->assertOk();
        $this->assertDatabaseMissing('customer_profile_photos', ['customer_id' => $quick->json('id')]);
    }

    public function test_full_edit_processing_failure_retains_old_details_and_returns_form_input(): void
    {
        [$user, , $customer] = $this->tenant();
        $first = $this->upload($user, $customer)->assertOk();
        $before = $customer->fresh()->getAttributes();
        $this->actingAs($user)->from('https://jewelflows.com/customers/'.$customer->id.'/edit')->put('https://jewelflows.com/customers/'.$customer->id, [
            'first_name' => 'Changed', 'last_name' => 'Example',
            'customer_photo' => ProfilePhotoFixtures::fromBytes('bad', 'bad.png'),
            'customer_photo_version' => $first->json('photo.version'),
        ])->assertRedirect()->assertSessionHasErrors('customer_photo')->assertSessionHasInput('first_name', 'Changed');
        $this->assertSame($before, $customer->fresh()->getAttributes());
    }

    public function test_photo_changes_preserve_populated_invoice_loan_kyc_and_balance_records(): void
    {
        [$user, $shop, $customer] = $this->tenant(true);
        TenantContext::runFor($shop->id, function () use ($customer, $user, $shop) {
            (new Invoice)->forceFill(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'status' => 'draft', 'gold_rate' => 6000, 'subtotal' => 1000, 'gst' => 30, 'total' => 1030])->save();
            KycDocument::create(['customer_id' => $customer->id, 'file_disk' => 'local', 'file_path' => 'kyc/keep.jpg', 'document_type' => 'pan_card']);
            app(DhiranService::class)->createLoan($shop, $customer, [[
                'description' => 'Synthetic Chain', 'metal_type' => 'gold', 'purity' => 22,
                'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => $user->id, 'status' => 'pending_evidence']);
        });
        Storage::disk('local')->put('kyc/keep.jpg', 'private evidence unchanged');
        $tables = ['customers', 'invoices', 'invoice_payments', 'customer_opening_balances', 'customer_gold_transactions', 'kyc_documents', 'dhiran_loans', 'dhiran_loan_items', 'dhiran_payments', 'dhiran_ledger_entries', 'dhiran_attachments'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        $first = $this->upload($user, $customer, '', true)->assertOk();
        $next = $this->upload($user, $customer, $first->json('photo.version'), true)->assertOk();
        $this->actingAs($user)->deleteJson($this->url($customer, true), ['customer_photo_version' => $next->json('photo.version')])->assertOk();
        $this->assertSame($before, $snapshot());
        $this->assertSame('private evidence unchanged', Storage::disk('local')->get('kyc/keep.jpg'));
    }

    public function test_full_forms_show_optional_staged_photo_controls(): void
    {
        [$user, , $customer] = $this->tenant();
        foreach (['https://jewelflows.com/customers/create', 'https://jewelflows.com/customers/'.$customer->id.'/edit'] as $url) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('data-photo-mode="form"', false)->assertSee('enctype="multipart/form-data"', false)
                ->assertSee('aria-label="Take photo"', false)->assertSee('title="Upload photo"', false)
                ->assertSee('customer-photo-icon', false)->assertSee('data-photo-dialog', false);
        }
    }

    public function test_retail_and_dhiran_profiles_and_lists_render_private_avatars(): void
    {
        foreach ([false, true] as $dhiran) {
            [$user, $shop, $customer] = $this->tenant($dhiran);
            if ($dhiran) {
                TenantContext::runFor($shop->id, fn () => app(DhiranService::class)->createLoan($shop, $customer, [[
                    'description' => 'Synthetic Chain', 'metal_type' => 'gold', 'purity' => 22,
                    'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
                ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => $user->id, 'status' => 'pending_evidence']));
            }
            $saved = $this->upload($user, $customer, '', $dhiran)->assertOk();
            $base = $dhiran ? 'https://dhiran.jewelflows.com/dhiran/borrowers' : 'https://jewelflows.com/customers';
            $this->actingAs($user)->get($base.'/'.$customer->id)->assertOk()
                ->assertSee('data-photo-mode="profile"', false)->assertSee('Change photo')->assertSee('Remove photo');
            $this->actingAs($user)->get($base)->assertOk()->assertSee('data-customer-avatar', false)
                ->assertSee($saved->json('photo.version'))->assertDontSee($this->row($customer)->file_path);
        }
    }
}
