<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A write refused because the shop is read-only was flashed only as a validation error. Screens without the inline
 * alert component (Add Customer, among others) showed nothing: the form came back empty with no reason given.
 * The refusal must also be flashed as `error`, which the layout always shows.
 */
class ReadOnlyShopWriteMessageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    public function test_refused_write_in_administrative_read_only_flashes_a_visible_error(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $shop->forceFill(['access_mode' => 'read_only', 'suspended_by' => $this->createPlatformAdmin()->id])->save();

        $this->actingAs($owner->fresh())
            ->from('/customers/create')
            ->post('/customers', ['first_name' => 'Read', 'last_name' => 'Only', 'mobile' => '9811122233'])
            ->assertRedirect('/customers/create')
            ->assertSessionHas('error');

        $this->assertSame(0, Customer::withoutGlobalScopes()->where('first_name', 'Read')->count());
    }

    public function test_refused_write_in_plain_read_only_flashes_a_visible_error(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $shop->forceFill(['access_mode' => 'read_only'])->save();

        $this->actingAs($owner->fresh())
            ->from('/customers/create')
            ->post('/customers', ['first_name' => 'Read', 'last_name' => 'Only', 'mobile' => '9811122233'])
            ->assertRedirect('/customers/create')
            ->assertSessionHas('error');
    }
}
