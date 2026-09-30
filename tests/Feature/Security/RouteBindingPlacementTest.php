<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Production runs `route:cache`, which restores compiled routes without
 * executing the route files — so a Route::bind() written in routes/*.php
 * silently does not exist there (tests and local dev still had it). Explicit
 * binders belong in a service provider.
 */
class RouteBindingPlacementTest extends TestCase
{
    public function test_no_route_file_defines_a_binder(): void
    {
        foreach (glob(base_path('routes/*.php')) as $file) {
            $this->assertStringNotContainsString('Route::bind(', file_get_contents($file), basename($file).' defines a binder that route:cache drops');
        }
    }

    public function test_the_platform_edition_request_binder_is_registered_by_a_provider(): void
    {
        $this->assertNotNull(app('router')->getBindingCallback('platformEditionRequest'));
    }
}
