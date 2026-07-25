<?php

namespace Tests\Feature\Masters;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 2 — sub-categories.index redirect.
 *
 * GET /sub-categories mapped to SubCategoryController@index, a method that does
 * not exist (its create/show/edit siblings were converted to redirect closures
 * but the index was missed), so a direct URL hit 500'd. Sub-categories are now
 * managed inline on the unified categories index page, so the route redirects
 * there — route name/method/permission gate all unchanged.
 */
class SubCategoryIndexRedirectTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_authorized_user_is_redirected_to_the_unified_categories_page(): void
    {
        [$user] = $this->createManufacturerTenant();

        $this->actingAs($user)
            ->get(route('sub-categories.index'))
            ->assertRedirect(route('categories.index'));
    }

    public function test_guest_is_still_auth_guarded_not_500(): void
    {
        $this->get(route('sub-categories.index'))
            ->assertRedirect(route('login'));
    }
}
