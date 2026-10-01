<?php

namespace Tests\Feature\Security;

use App\Models\Repair;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A repair photo is a picture of a customer's own jewellery, taken at the
 * counter. Every route that shows one needs a signed-in member of the shop with
 * repairs.view; nothing public links to one. Until 2026-10-01 the files sat on
 * the public disk, so nginx served them to anyone holding the URL (seven on
 * production). They belong on the private disk, behind those same rules.
 */
class RepairImagePrivacyTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    /** A repair of $shop whose photo is on $disk. */
    private function repairWithPhoto(int $shopId, string $disk, string $bytes = 'PHOTO-BYTES-OF-A-CUSTOMERS-RING'): Repair
    {
        $customer = $this->createCustomer($shopId);
        $path = "repairs/{$shopId}/".uniqid('photo-', true).'.png';
        Storage::disk($disk)->put($path, $bytes);

        return TenantContext::runFor($shopId, fn () => Repair::create([
            'shop_id' => $shopId, 'customer_id' => $customer->id, 'item_description' => 'Ring', 'description' => 'Resize',
            'gross_weight' => 4.2, 'purity' => 22, 'estimated_cost' => 300, 'status' => 'received',
            'image' => $path, 'image_path' => $path,
        ]));
    }

    private function webGet(User $user, string $url)
    {
        return TenantContext::runFor((int) $user->shop_id, fn () => $this->actingAs($user)->get($url));
    }

    public function test_a_photo_uploaded_on_the_web_is_stored_privately_never_on_the_public_disk(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $customer = $this->createCustomer($shop->id);

        $this->actingAs($user)->post(route('repairs.store'), [
            'customer_id' => $customer->id, 'item_description' => 'Chain', 'description' => 'Fix lock',
            'gross_weight' => 8.5, 'purity' => 22, 'estimated_cost' => 450,
            'image' => UploadedFile::fake()->image('photo.png', 64, 64),
        ])->assertRedirect(route('repairs.index'));

        $repair = Repair::withoutTenant()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
        Storage::disk('local')->assertExists($repair->image_path);
        Storage::disk('public')->assertMissing($repair->image_path);
    }

    public function test_a_photo_uploaded_from_the_mobile_app_is_stored_privately(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $customer = $this->createCustomer($shop->id);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/repairs', [
            'customer_id' => $customer->id, 'item_description' => 'Chain', 'description' => 'Fix lock',
            'gross_weight' => 8.5, 'purity' => 22, 'estimated_cost' => 450, 'image_base64' => self::PNG,
        ])->assertCreated();

        Storage::disk('local')->assertExists((string) $response->json('image_path'));
        Storage::disk('public')->assertMissing((string) $response->json('image_path'));
        $this->assertStringNotContainsString('/storage/', (string) $response->json('image_url'));
    }

    public function test_a_member_of_the_shop_with_repairs_view_gets_the_photo_through_the_web_route(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $repair = $this->repairWithPhoto($shop->id, 'local');

        $response = $this->webGet($user, route('repairs.image', $repair));

        $response->assertOk();
        $this->assertSame('PHOTO-BYTES-OF-A-CUSTOMERS-RING', $response->streamedContent());
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_detail_page_links_the_route_not_a_storage_url(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $repair = $this->repairWithPhoto($shop->id, 'local');

        $page = $this->webGet($user, route('repairs.show', $repair))->assertOk();

        $page->assertSee(route('repairs.image', $repair), false);
        $page->assertDontSee('/storage/repairs/', false);
    }

    public function test_another_shop_cannot_fetch_it_by_number(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        [$stranger] = $this->createRetailerTenant();
        $repair = $this->repairWithPhoto($shop->id, 'local');

        $response = $this->webGet($stranger, route('repairs.image', $repair));

        $response->assertNotFound();
        $this->assertStringNotContainsString('PHOTO-BYTES', (string) $response->getContent());
    }

    public function test_a_colleague_without_repairs_view_is_refused(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $repair = $this->repairWithPhoto($shop->id, 'local');
        $role = (new Role)->forceFill(['shop_id' => $shop->id, 'name' => 'counter', 'display_name' => 'Counter']);
        $role->save();
        $colleague = (new User)->forceFill([
            'shop_id' => $shop->id, 'role_id' => $role->id, 'name' => 'Colleague', 'mobile_number' => '9811100022',
            'password' => Hash::make('irrelevant-for-this-test'), 'is_active' => true,
        ]);
        $colleague->save();
        $this->grantOnlyPermissions($colleague, ['customers.view']);

        $this->webGet($colleague, route('repairs.image', $repair))->assertForbidden();
    }

    public function test_nobody_signed_in_gets_no_bytes(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $repair = $this->repairWithPhoto($shop->id, 'local');

        $response = $this->get(route('repairs.image', $repair));

        $response->assertRedirect(route('login'));
        $this->assertStringNotContainsString('PHOTO-BYTES', (string) $response->getContent());
    }

    public function test_a_photo_still_on_the_public_disk_is_served_by_the_route_until_it_is_relocated(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $repair = $this->repairWithPhoto($shop->id, 'public', 'LEGACY-PHOTO');

        $response = $this->webGet($user, route('repairs.image', $repair));

        $response->assertOk();
        $this->assertSame('LEGACY-PHOTO', $response->streamedContent());
    }

    public function test_the_mobile_api_hands_out_a_link_that_works_without_headers_and_expires(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $repair = $this->repairWithPhoto($shop->id, 'local');
        Sanctum::actingAs($user);

        $url = (string) $this->getJson("/api/mobile/repairs/{$repair->id}")->assertOk()->json('image_url');
        $this->assertStringContainsString("/api/mobile/repairs/{$repair->id}/image", $url);
        $this->assertStringContainsString('signature=', $url);
        $listed = collect($this->getJson('/api/mobile/repairs')->assertOk()->json('data'))->firstWhere('id', $repair->id);
        $this->assertStringContainsString('signature=', (string) data_get($listed, 'image_url'));

        // The released app loads image_url with a plain <Image>: no Authorization header.
        $this->app['auth']->forgetGuards();
        $fetched = $this->get($url);
        $fetched->assertOk();
        $this->assertSame('PHOTO-BYTES-OF-A-CUSTOMERS-RING', $fetched->streamedContent());

        $this->travel(16)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_the_mobile_link_is_refused_without_its_signature_or_with_another_repairs_number(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $repair = $this->repairWithPhoto($shop->id, 'local');
        $other = $this->repairWithPhoto($shop->id, 'local', 'ANOTHER-PHOTO');
        Sanctum::actingAs($user);
        $url = (string) $this->getJson("/api/mobile/repairs/{$repair->id}")->json('image_url');
        $this->app['auth']->forgetGuards();

        $this->get("/api/mobile/repairs/{$repair->id}/image")->assertForbidden();
        $this->get(preg_replace_callback('/signature=([0-9a-f])/', fn ($m) => 'signature='.($m[1] === '0' ? '1' : '0'), $url, 1))->assertForbidden();
        $swapped = $this->get(str_replace("/repairs/{$repair->id}/image", "/repairs/{$other->id}/image", $url));
        $swapped->assertForbidden();
        $this->assertStringNotContainsString('ANOTHER-PHOTO', (string) $swapped->getContent());
    }

    public function test_a_signed_in_user_of_another_shop_is_given_no_link(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        [$stranger] = $this->createRetailerTenant();
        $repair = $this->repairWithPhoto($shop->id, 'local');
        Sanctum::actingAs($stranger);

        $this->getJson("/api/mobile/repairs/{$repair->id}")->assertNotFound();
    }

    public function test_relocation_moves_every_file_to_the_private_disk_and_deletes_nothing(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $repair = $this->repairWithPhoto($shop->id, 'public', 'LEGACY-PHOTO');
        Storage::disk('public')->put("repairs/{$shop->id}/no-row-names-me.webp", 'ORPHAN-PHOTO');

        $this->artisan('repairs:relocate-images')->assertExitCode(0);   // dry run
        Storage::disk('public')->assertExists($repair->image_path);

        $this->artisan('repairs:relocate-images', ['--execute' => true])->assertExitCode(0);
        Storage::disk('public')->assertMissing($repair->image_path);
        Storage::disk('public')->assertMissing("repairs/{$shop->id}/no-row-names-me.webp");
        $this->assertSame('LEGACY-PHOTO', Storage::disk('local')->get($repair->image_path));
        $this->assertSame('ORPHAN-PHOTO', Storage::disk('local')->get("repairs/{$shop->id}/no-row-names-me.webp"));

        $this->artisan('repairs:relocate-images', ['--verify' => true])->assertExitCode(0);
        $this->assertSame('LEGACY-PHOTO', $this->webGet($user, route('repairs.image', $repair))->assertOk()->streamedContent());
    }

    public function test_relocation_never_overwrites_a_different_file_already_on_the_private_disk(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        Storage::disk('public')->put("repairs/{$shop->id}/same-name.webp", 'PUBLIC-VERSION');
        Storage::disk('local')->put("repairs/{$shop->id}/same-name.webp", 'PRIVATE-VERSION');

        $this->artisan('repairs:relocate-images', ['--execute' => true])->assertExitCode(1);

        $this->assertSame('PRIVATE-VERSION', Storage::disk('local')->get("repairs/{$shop->id}/same-name.webp"));
        $this->assertSame('PUBLIC-VERSION', Storage::disk('public')->get("repairs/{$shop->id}/same-name.webp"));
        $this->artisan('repairs:relocate-images', ['--verify' => true])->assertExitCode(1);
    }

    public function test_deleting_a_repair_removes_its_photo_wherever_it_is(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $private = $this->repairWithPhoto($shop->id, 'local');
        $legacy = $this->repairWithPhoto($shop->id, 'public');

        foreach ([$private, $legacy] as $repair) {   // one tenant context per request, as in production
            TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->delete(route('repairs.destroy', $repair))->assertRedirect(route('repairs.index')));
        }

        Storage::disk('local')->assertMissing($private->image_path);
        Storage::disk('public')->assertMissing($legacy->image_path);
    }
}
