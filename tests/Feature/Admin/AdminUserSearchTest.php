<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The admin Users search also matched users.first_name/last_name, columns dropped in January
 * (clean_users_table): any search term returned a 500.
 */
class AdminUserSearchTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    public function test_search_by_name_and_status_lists_matching_users(): void
    {
        $this->withoutVite();
        [$owner] = $this->createRetailerTenant();
        $owner->forceFill(['name' => 'Searchable Owner'])->save();

        $this->actingAs($this->createPlatformAdmin(), 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->get('/admin/users?q=Searchable&status=active')
            ->assertOk()
            ->assertSee('Searchable Owner');
    }
}
