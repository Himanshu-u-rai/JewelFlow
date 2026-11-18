<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public policy pages (owner, 2026-11-16) open for anyone without signing in, link to each other, and are
 * linked from the landing and login pages. Unfilled business details show as a visible note, never invented text.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase; // the web middleware reads platform_settings (maintenance mode)

    private const PAGES = [
        'legal.about'    => 'About JewelFlows',
        'legal.contact'  => 'Contact Us',
        'legal.terms'    => 'Terms &amp; Conditions',
        'legal.privacy'  => 'Privacy Policy',
        'legal.refund'   => 'Refund &amp; Cancellation Policy',
        'legal.delivery' => 'Service Delivery Policy',
    ];

    public function test_every_policy_page_is_public_and_links_to_the_others(): void
    {
        foreach (self::PAGES as $route => $heading) {
            $response = $this->get(route($route))->assertOk()->assertSee('<h1>' . $heading . '</h1>', false);
            foreach (array_keys(self::PAGES) as $other) {
                $response->assertSee('href="' . route($other) . '"', false);
            }
        }
    }

    public function test_missing_business_details_show_as_a_note_and_filled_ones_show_as_written(): void
    {
        config(['legal.address' => null, 'legal.phone' => '+91 90000 00000']);

        $this->get(route('legal.contact'))
            ->assertSee('[registered address: to be added]')
            ->assertSee('+91 90000 00000')
            ->assertDontSee('[phone number: to be added]');
    }

    public function test_landing_and_login_pages_link_to_the_policies(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('href="' . route('legal.terms') . '"', false)
            ->assertSee('href="' . route('legal.privacy') . '"', false);
    }
}
