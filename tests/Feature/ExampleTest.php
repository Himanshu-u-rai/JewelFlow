<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // For a guest, / renders the public landing page (the marketing home);
        // it redirects to /dashboard or the admin dashboard only when already
        // authenticated. The old assertion (redirect to /login) predates the
        // landing page.
        $response = $this->get('/');

        $response->assertOk();
    }
}
