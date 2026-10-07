<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\ProductPromotionService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class ProductPromotionRecognitionTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    private ProductPromotionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductPromotionService::class);
        config(['platform.cross_promotion.enabled' => true,
            'platform.cross_promotion.dhiran_register_url' => 'https://dhiran.example.test/register',
            'platform.cross_promotion.erp_register_url' => 'https://example.test/register']);
    }

    private function owner(string $realm = 'erp'): User
    {
        $shop = $this->createShop($realm === 'dhiran' ? 'dhiran' : 'retailer');
        $owner = $this->createOwnerUser($shop, $this->createOwnerRole($shop->id));
        $owner->forceFill(['realm' => $realm, 'password' => Hash::make('password')])->save();

        return $owner->fresh();
    }

    private function url(User $owner, string $action = ''): string
    {
        return ($owner->realm === 'dhiran' ? 'https://dhiran.example.test/dhiran' : 'https://example.test')
            .'/product-preferences'.($action ? '/'.$action : '');
    }

    private function request(User $owner): Request
    {
        TenantContext::set($owner->shop_id);
        $request = Request::create($this->url($owner));
        $request->setUserResolver(fn () => $owner);

        return $request;
    }

    private function connect(User $source, User $target): string
    {
        $code = $this->service->start($source, 'password');
        $id = $this->service->requests($source)->whereNull('consumed_at')->value('id');
        $this->service->approve($target, 'password', $code);
        $this->service->finish($source, 'password', $id);

        return DB::table('product_recognitions')->where('request_id', $id)->value('id');
    }

    public function test_stale_recognition_reader_cannot_revoke_a_new_confirmation(): void
    {
        $source = $this->owner();
        $target = $this->owner('dhiran');
        $id = $this->connect($source, $target);
        $selected = false;
        $stale = false;
        $reconnected = false;
        DB::listen(function ($query) use ($source, &$selected, &$stale): void {
            if (! $selected && str_starts_with($query->sql, 'select * from "product_recognitions"')) {
                $selected = true;
                // Normal model event invalidates the old consent after the
                // reader has taken its row snapshot.
                $source->forceFill(['password' => Hash::make('password')])->save();
                $stale = true;
            }
        });
        DB::connection()->beforeExecuting(function ($sql) use ($source, $target, &$stale, &$reconnected): void {
            if ($stale && str_starts_with($sql, 'update "product_recognitions"')) {
                $stale = false;
                $this->connect($source, $target);
                $reconnected = true;
            }
        });

        $this->assertCount(0, $this->service->recognitions($source));
        $this->assertTrue($reconnected, 'Reconnect must occur before the stale reader writes');
        $this->assertDatabaseHas('product_recognitions', ['id' => $id, 'revoked_at' => null]);
        $this->assertCount(1, $this->service->recognitions($source->fresh()));
    }

    public function test_same_contact_details_do_not_link_accounts_and_exposure_is_per_owner(): void
    {
        $retail = $this->owner();
        $dhiran = $this->owner('dhiran');
        $dhiran->forceFill(['mobile_number' => $retail->mobile_number, 'email' => $retail->email])->save();
        $this->assertNotNull($this->service->claimIntroduction($this->request($retail)));
        $this->assertNull($this->service->claimIntroduction($this->request($retail->fresh())));
        $this->travel(2)->days();
        $this->assertNull($this->service->claimIntroduction($this->request($retail->fresh())));
        $this->assertNotNull($this->service->claimIntroduction($this->request($dhiran)));
        $this->assertDatabaseCount('product_promotion_exposures', 2);
        $this->assertDatabaseCount('product_recognitions', 0);
    }

    public function test_profile_deactivation_consumes_pending_consent_and_revokes_recognition(): void
    {
        [$source] = $this->createRetailerTenant();
        $source->forceFill(['password' => Hash::make('password')])->save();
        $target = $this->owner('dhiran');
        $id = $this->connect($source, $target);
        $this->service->start($source, 'password');
        $request = $this->service->requests($source)->whereNull('consumed_at')->value('id');
        $this->actingAs($source)->post('https://example.test/profile/deactivate', ['password' => 'password'])->assertRedirect('/login');
        $this->assertFalse($source->fresh()->is_active);
        $this->assertSame('suspended', $source->fresh()->employment_status);
        $this->assertDatabaseMissing('product_recognition_requests', ['id' => $request, 'consumed_at' => null]);
        $this->assertDatabaseMissing('product_recognitions', ['id' => $id, 'revoked_at' => null]);
    }

    public function test_security_save_rolls_back_when_invalidation_fails(): void
    {
        $source = $this->owner();
        $target = $this->owner('dhiran');
        $id = $this->connect($source, $target);
        $this->service->start($source, 'password');
        $request = $this->service->requests($source)->whereNull('consumed_at')->value('id');
        $before = $source->password;
        $fail = true;
        DB::connection()->beforeExecuting(function ($sql) use (&$fail): void {
            if ($fail && str_starts_with($sql, 'update "product_recognitions"')) {
                $fail = false;
                throw new \RuntimeException('synthetic invalidation failure');
            }
        });
        try {
            $source->forceFill(['password' => Hash::make('replacement')])->save();
            $this->fail('A failed invalidation must abort the security change.');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic invalidation failure', $e->getMessage());
        }
        $this->assertSame($before, $source->fresh()->password);
        $this->assertDatabaseHas('product_recognition_requests', ['id' => $request, 'consumed_at' => null]);
        $this->assertDatabaseHas('product_recognitions', ['id' => $id, 'revoked_at' => null]);
        $this->assertCount(1, $this->service->recognitions($source->fresh()));
    }

    public function test_quiet_security_save_still_invalidates_consent(): void
    {
        $source = $this->owner();
        $target = $this->owner('dhiran');
        $id = $this->connect($source, $target);
        $source->forceFill(['password' => Hash::make('replacement')])->saveQuietly();
        $this->assertDatabaseMissing('product_recognitions', ['id' => $id, 'revoked_at' => null]);
    }

    public function test_billing_deactivation_and_restore_do_not_revoke_recognition(): void
    {
        $source = $this->owner();
        $target = $this->owner('dhiran');
        $this->connect($source, $target);
        $shop = $source->shop;
        foreach (['suspended', 'active'] as $mode) {
            $shop->forceFill(['access_mode' => $mode, 'is_active' => $mode === 'active',
                'deactivated_at' => $mode === 'active' ? null : now()])->save();
            $this->assertCount(1, $this->service->recognitions($target));
            $this->assertNull($this->service->claimIntroduction($this->request($target)));
        }
    }

    public function test_expired_or_read_only_owners_can_manage_metadata_but_inactive_users_cannot(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        foreach (['erp', 'dhiran'] as $realm) {
            $source = $this->owner($realm);
            $target = $this->owner($realm === 'erp' ? 'dhiran' : 'erp');
            $id = $this->connect($source, $target);
            $pair = DB::table('product_recognitions')->where('id', $id)->first();
            $this->assertSame($realm === 'erp' ? $source->id : $target->id,
                $pair->erp_user_id, 'The fixture must return this pair, not an unrelated latest row.');
            $this->assertSame($realm === 'dhiran' ? $source->id : $target->id, $pair->dhiran_user_id);
            foreach (['suspended', 'read_only'] as $mode) {
                $source->shop->forceFill(['access_mode' => $mode, 'is_active' => false,
                    'deactivated_at' => now(), 'suspended_by' => null,
                    'suspension_reason' => 'Subscription expired'])->save();
                $this->actingAs($source->fresh())->get($this->url($source))->assertOk();
                $this->actingAs($source->fresh())->post($this->url($source, 'preference'), ['choice' => 'opt_out'])->assertRedirect();
                $this->assertDatabaseHas('product_promotion_preferences', ['user_id' => $source->id, 'choice' => 'opt_out']);
            }
            $intruder = $this->owner($realm);
            $before = DB::table('product_recognitions')->where('id', $id)->first();
            $this->actingAs($intruder)->post($this->url($intruder, 'revoke'), ['recognition_id' => $id])->assertNotFound();
            $this->assertEquals($before, DB::table('product_recognitions')->where('id', $id)->first());
            $this->actingAs($source->fresh())->post($this->url($source, 'revoke'), ['recognition_id' => $id])->assertRedirect();
            $this->assertDatabaseMissing('product_recognitions', ['id' => $id, 'revoked_at' => null]);
            $after = DB::table('product_recognitions')->where('id', $id)->first();
            $this->assertNotNull($after->revoked_at);
            $this->assertSame($before->request_id, $after->request_id);
            $this->assertNotNull(DB::table('product_recognition_requests')->where('id', $after->request_id)->value('consumed_at'));
            $this->actingAs($source->fresh())->post($this->url($source, 'revoke'), ['recognition_id' => $id])->assertNotFound();
            $this->assertEquals($after, DB::table('product_recognitions')->where('id', $id)->first());
            $source->forceFill(['is_active' => false, 'employment_status' => 'suspended'])->save();
            $this->actingAs($source->fresh())->get($this->url($source))->assertForbidden();
            $this->actingAs($source->fresh())->post($this->url($source, 'preference'), ['choice' => 'already_use'])->assertForbidden();
            $this->assertDatabaseHas('product_promotion_preferences', ['user_id' => $source->id, 'choice' => 'opt_out']);
        }
        // No business-route gate is removed by the metadata route exception.
        $this->assertContains('account.active', app('router')->getRoutes()->getByName('invoices.index')->gatherMiddleware());
        $this->assertContains('subscription.active', app('router')->getRoutes()->getByName('invoices.index')->gatherMiddleware());
    }

    public function test_reconfirmation_displays_current_consent_date_not_original_pair_creation(): void
    {
        $source = $this->owner();
        $target = $this->owner('dhiran');
        $this->travelTo(now()->startOfDay());
        $id = $this->connect($source, $target);
        $original = now()->format('d M Y');
        $this->service->revoke($source, $id);
        $this->travel(2)->days();
        $this->connect($source, $target);
        foreach ([$source, $target] as $owner) {
            $this->actingAs($owner)->get($this->url($owner))->assertOk()
                ->assertSee('other product confirmed on '.now()->format('d M Y'))
                ->assertDontSee('other product confirmed on '.$original);
        }
        $this->travelBack();
    }

    public function test_permanent_preferences_do_not_claim_purchase_or_grant_an_edition(): void
    {
        $owner = $this->owner();
        $before = DB::table('shop_editions')->count();
        $this->actingAs($owner)->post($this->url($owner, 'preference'), ['choice' => 'already_use', 'shop_id' => 999])
            ->assertRedirect();
        $this->assertDatabaseHas('product_promotion_preferences', ['user_id' => $owner->id, 'shop_id' => $owner->shop_id, 'choice' => 'already_use']);
        $this->assertNull($this->service->claimIntroduction($this->request($owner)));
        $this->assertDatabaseCount('product_recognitions', 0);
        $this->assertSame($before, DB::table('shop_editions')->count());
        $this->actingAs($owner)->post($this->url($owner, 'preference'), ['choice' => 'opt_out'])->assertRedirect();
        $this->assertDatabaseHas('product_promotion_preferences', ['user_id' => $owner->id, 'choice' => 'opt_out']);
    }

    public function test_cross_shop_role_assignment_and_staff_access_are_refused(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        try {
            DB::transaction(fn () => $owner->forceFill(['role_id' => $other->role_id])->save());
            $this->fail('A role from another shop must be rejected by the database.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('23503', $e->getCode());
        }
        $role = Role::withoutTenant()->findOrFail($owner->fresh()->role_id);
        $role->name = 'staff';
        $role->save();
        $owner = $owner->fresh();
        $this->actingAs($owner)->get($this->url($owner))->assertForbidden();
        $this->actingAs($owner)->post($this->url($owner, 'start'), ['password' => 'password', 'consent' => '1'])->assertForbidden();
        $this->assertNull($this->service->claimIntroduction($this->request($owner)));
        $role = Role::withoutTenant()->findOrFail($other->role_id);
        $role->name = 'staff';
        $role->save();
        $this->actingAs($other)->post($this->url($other, 'preference'), ['choice' => 'opt_out'])->assertForbidden();
        $this->assertDatabaseCount('product_recognition_requests', 0);
        $this->assertDatabaseCount('product_promotion_preferences', 0);
    }

    public function test_missing_tenant_wrong_host_and_disabled_url_hide_the_promotion(): void
    {
        $owner = $this->owner();
        $request = $this->request($owner);
        TenantContext::clear();
        $this->assertNull($this->service->claimIntroduction($request));
        $this->actingAs($owner)->get('https://dhiran.example.test/product-preferences')->assertRedirect();
        config(['platform.cross_promotion.dhiran_register_url' => '']);
        $this->assertNull($this->service->claimIntroduction($this->request($owner)));
        $this->assertDatabaseCount('product_promotion_exposures', 0);
    }

    public function test_preferences_keep_each_products_phone_navigation_opener(): void
    {
        $retail = $this->owner();
        $this->actingAs($retail)->get($this->url($retail))->assertOk()->assertSee('class="mobile-menu-btn"', false);
        $dhiran = $this->owner('dhiran');
        $this->actingAs($dhiran)->get($this->url($dhiran))->assertOk()->assertSee('class="dh-header-toggle"', false);
    }

    public function test_lookup_failure_does_not_break_dashboard_or_show_an_ad(): void
    {
        $owner = $this->owner();
        $service = \Mockery::mock(ProductPromotionService::class)->makePartial();
        $service->shouldReceive('recognitions')->andThrow(new \RuntimeException('synthetic unavailable lookup'));
        $this->assertNull($service->claimIntroduction($this->request($owner)));
        $this->assertDatabaseCount('product_promotion_exposures', 0);
    }

    public function test_database_lookup_failure_does_not_poison_the_callers_transaction(): void
    {
        $owner = $this->owner();
        \Illuminate\Support\Facades\Schema::rename('product_recognitions', 'unavailable_product_recognitions');
        $this->assertNull($this->service->claimIntroduction($this->request($owner)));
        // A swallowed PostgreSQL error must roll back its savepoint, not leave
        // the dashboard's enclosing transaction unable to execute any query.
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public function test_two_password_confirmations_plus_final_source_consent_are_required(): void
    {
        $retail = $this->owner();
        $dhiran = $this->owner('dhiran');
        $this->actingAs($retail)->post($this->url($retail, 'start'), ['password' => 'bad', 'consent' => '1'])
            ->assertSessionHasErrors('password');
        $this->actingAs($retail)->post($this->url($retail, 'start'), ['password' => 'password'])
            ->assertSessionHasErrors('consent');
        $response = $this->actingAs($retail)->post($this->url($retail, 'start'), ['password' => 'password', 'consent' => '1']);
        $response->assertRedirect()->assertSessionHas('recognition_code');
        $code = session('recognition_code');
        $row = $this->service->requests($retail)->whereNull('consumed_at')->first();
        $this->assertSame(hash('sha256', $code), $row->code_hash);
        $this->assertStringNotContainsString($code, json_encode((array) $row));
        $this->actingAs($dhiran)->post($this->url($dhiran, 'approve'), ['code' => $code, 'password' => 'bad', 'consent' => '1'])
            ->assertSessionHasErrors('password');
        $this->actingAs($dhiran)->post($this->url($dhiran, 'approve'), ['code' => $code, 'password' => 'password', 'consent' => '1'])->assertRedirect();
        $this->assertDatabaseCount('product_recognitions', 0);
        $this->actingAs($retail)->post($this->url($retail, 'finish'), ['request_id' => $row->id, 'password' => 'password', 'consent' => '1'])->assertRedirect();
        $this->assertDatabaseCount('product_recognitions', 1);
        $this->assertNull($this->service->claimIntroduction($this->request($retail)));
        $this->assertNull($this->service->claimIntroduction($this->request($dhiran)));
    }

    public function test_wrong_shop_cannot_finish_cancel_or_revoke_someone_elses_request(): void
    {
        $retail = $this->owner();
        $intruder = $this->owner();
        $dhiran = $this->owner('dhiran');
        $code = $this->service->start($retail, 'password');
        $id = $this->service->requests($retail)->whereNull('consumed_at')->value('id');
        $this->service->approve($dhiran, 'password', $code);
        $this->actingAs($intruder)->post($this->url($intruder, 'finish'), ['request_id' => $id, 'password' => 'password', 'consent' => '1'])->assertStatus(409);
        $this->actingAs($intruder)->post($this->url($intruder, 'cancel'), ['request_id' => $id])->assertNotFound();
        $this->service->finish($retail, 'password', $id);
        $link = DB::table('product_recognitions')->value('id');
        $this->actingAs($intruder)->post($this->url($intruder, 'revoke'), ['recognition_id' => $link])->assertNotFound();
        $this->assertDatabaseHas('product_recognitions', ['id' => $link, 'revoked_at' => null]);
    }

    public function test_used_expired_wrong_realm_and_wrong_environment_codes_are_refused(): void
    {
        $retail = $this->owner();
        $dhiran = $this->owner('dhiran');
        $code = $this->service->start($retail, 'password');
        $data = ['password' => 'password', 'consent' => '1', 'code' => $code];
        $this->actingAs($retail)->post($this->url($retail, 'approve'), $data)->assertSessionHasErrors('code');
        DB::table('product_recognition_requests')->update(['environment' => 'different-environment']);
        $this->actingAs($dhiran)->post($this->url($dhiran, 'approve'), $data)->assertSessionHasErrors('code');
        DB::table('product_recognition_requests')->update(['environment' => app()->environment()]);
        $this->service->approve($dhiran, 'password', $code);
        $this->actingAs($dhiran)->post($this->url($dhiran, 'approve'), $data)->assertSessionHasErrors('code');
        $new = $this->service->start($retail, 'password');
        $this->travel(11)->minutes();
        $this->actingAs($dhiran)->post($this->url($dhiran, 'approve'), array_replace($data, ['code' => $new]))->assertSessionHasErrors('code');
        $this->assertDatabaseCount('product_recognitions', 0);
    }

    public function test_recognition_preserves_tenant_data_editions_and_billing_and_ignores_subscription_state(): void
    {
        [$retail] = $this->createRetailerTenant();
        $retail->forceFill(['password' => Hash::make('password')])->save();
        $dhiran = $this->owner('dhiran');
        $editions = DB::table('shop_editions')->orderBy('id')->get()->toJson();
        $subscriptions = DB::table('shop_subscriptions')->orderBy('id')->get()->toJson();
        $foreignCustomer = TenantContext::runFor($dhiran->shop_id, fn () => $this->createCustomer($dhiran->shop_id));
        $this->connect($retail, $dhiran);
        $this->assertSame($editions, DB::table('shop_editions')->orderBy('id')->get()->toJson());
        $this->assertSame($subscriptions, DB::table('shop_subscriptions')->orderBy('id')->get()->toJson());
        TenantContext::runFor($retail->shop_id, fn () => $this->assertNull(Customer::find($foreignCustomer->id)));
        foreach (['trial', 'active', 'expired', 'cancelled'] as $status) {
            // No classification is inferred from these values; existing-owner evidence is enough.
            DB::table('shop_subscriptions')->where('shop_id', $retail->shop_id)->update(['status' => $status]);
            $this->assertNull($this->service->claimIntroduction($this->request($dhiran)));
        }
    }

    public function test_phone_change_keeps_recognition_but_password_or_owner_role_change_revokes_it(): void
    {
        $retail = $this->owner();
        $dhiran = $this->owner('dhiran');
        $id = $this->connect($retail, $dhiran);
        $retail->forceFill(['mobile_number' => '9888777666'])->save();
        $this->assertCount(1, $this->service->recognitions($dhiran));
        $retail->forceFill(['password' => Hash::make('new-password')])->save();
        $this->assertDatabaseMissing('product_recognitions', ['id' => $id, 'revoked_at' => null]);
        $retail->forceFill(['password' => Hash::make('password')])->save();
        $this->connect($retail, $dhiran);
        $role = Role::withoutTenant()->findOrFail($retail->role_id);
        $role->name = 'staff';
        $role->save();
        $role->name = 'owner';
        $role->save();
        $this->assertCount(0, $this->service->recognitions($dhiran));
    }

    public function test_both_directions_converge_and_either_owner_can_revoke_without_restoring_opt_out(): void
    {
        $retail = $this->owner();
        $dhiran = $this->owner('dhiran');
        $this->service->choose($retail, 'opt_out');
        $this->connect($retail, $dhiran);
        $this->connect($dhiran, $retail);
        $this->assertDatabaseCount('product_recognitions', 1);
        $id = DB::table('product_recognitions')->value('id');
        $this->actingAs($dhiran)->post($this->url($dhiran, 'revoke'), ['recognition_id' => $id])->assertRedirect();
        $this->assertCount(0, $this->service->recognitions($retail));
        $this->assertNull($this->service->claimIntroduction($this->request($retail)));
    }

    public function test_target_url_never_falls_back_to_production_in_testing_and_return_url_is_ignored(): void
    {
        $dhiran = $this->owner('dhiran');
        config(['platform.cross_promotion.erp_register_url' => null]);
        $this->assertNull($this->service->targetUrl($this->request($dhiran), $dhiran));
        config(['platform.cross_promotion.erp_register_url' => 'javascript:alert(1)']);
        $this->assertNull($this->service->targetUrl($this->request($dhiran), $dhiran));
        $this->actingAs($dhiran)->post($this->url($dhiran, 'start'), [
            'password' => 'password', 'consent' => '1', 'return_url' => 'https://attacker.example',
        ])->assertRedirect($this->url($dhiran));
    }

    public function test_approved_target_can_withdraw_before_final_confirmation(): void
    {
        $source = $this->owner();
        $target = $this->owner('dhiran');
        $code = $this->service->start($source, 'password');
        $id = $this->service->requests($source)->whereNull('consumed_at')->value('id');
        $this->service->approve($target, 'password', $code);
        $this->actingAs($target)->post($this->url($target, 'cancel'), ['request_id' => $id])->assertRedirect();
        $this->actingAs($source)->post($this->url($source, 'finish'), [
            'request_id' => $id, 'password' => 'password', 'consent' => '1',
        ])->assertStatus(409);
        $this->assertDatabaseCount('product_recognitions', 0);
    }

    public function test_revocation_cancels_outstanding_approved_requests_for_that_pair(): void
    {
        $source = $this->owner();
        $target = $this->owner('dhiran');
        $link = $this->connect($source, $target);
        $code = $this->service->start($source, 'password');
        $id = $this->service->requests($source)->whereNull('consumed_at')->value('id');
        $this->service->approve($target, 'password', $code);
        $this->service->revoke($target, $link);
        $this->actingAs($source)->post($this->url($source, 'finish'), [
            'request_id' => $id, 'password' => 'password', 'consent' => '1',
        ])->assertStatus(409);
        $this->assertCount(0, $this->service->recognitions($source));
    }

    public function test_shop_reassignment_invalidates_pending_approval_even_after_restoration(): void
    {
        $source = $this->owner();
        $target = $this->owner('dhiran');
        $other = $this->owner('dhiran');
        $code = $this->service->start($source, 'password');
        $id = $this->service->requests($source)->whereNull('consumed_at')->value('id');
        $this->service->approve($target, 'password', $code);
        $old = ['shop_id' => $target->shop_id, 'role_id' => $target->role_id];
        $target->forceFill(['shop_id' => $other->shop_id, 'role_id' => $other->role_id])->save();
        $target->forceFill($old)->save();
        $this->actingAs($source)->post($this->url($source, 'finish'), [
            'request_id' => $id, 'password' => 'password', 'consent' => '1',
        ])->assertStatus(409);
        $this->assertDatabaseCount('product_recognitions', 0);
    }

    public function test_preference_routes_retain_web_csrf_and_rate_limiting(): void
    {
        $router = app('router');
        foreach (['product-preferences', 'dhiran.product-preferences'] as $prefix) {
            foreach (['preference', 'start', 'approve', 'finish', 'cancel', 'revoke'] as $action) {
                $route = $router->getRoutes()->getByName($prefix.'.'.$action);
                $this->assertSame(['POST'], $route->methods());
                $this->assertContains('web', $route->gatherMiddleware());
                $this->assertContains('throttle:6,1', $route->gatherMiddleware());
                $this->assertContains('tenant', $route->gatherMiddleware());
            }
        }
        // Exercise the actual CSRF middleware with its testing bypass disabled.
        $middleware = new class($this->app, $this->app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };
        $request = Request::create('https://example.test/product-preferences/start', 'POST');
        $request->setLaravelSession($this->app['session']->driver());
        $this->expectException(\Illuminate\Session\TokenMismatchException::class);
        $middleware->handle($request, fn () => response('must not reach the controller'));
    }
}
