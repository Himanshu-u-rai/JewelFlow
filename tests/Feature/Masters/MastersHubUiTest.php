<?php

namespace Tests\Feature\Masters;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class MastersHubUiTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function pageFor(User $user): \DOMXPath
    {
        $html = $this->actingAs($user)->get(route('masters.index'))->assertOk()->getContent();
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($dom);
    }

    public function test_hub_keeps_named_groups_and_native_links_with_decorative_navigation_cues(): void
    {
        [$owner] = $this->createRetailerTenant();
        $xp = $this->pageFor($owner);

        $this->assertSame(1, $xp->query('//div[contains(@class,"masters-page")]')->length);
        $sections = $xp->query('//section[contains(@class,"masters-section")]');
        $this->assertSame(3, $sections->length);
        foreach ($sections as $section) {
            $headingId = $section->getAttribute('aria-labelledby');
            $this->assertSame(1, $xp->query('.//h2[@id="'.$headingId.'"]', $section)->length);
        }

        $links = $xp->query('//a[@data-masters-card]');
        $this->assertSame(6, $links->length);
        foreach ($links as $link) {
            $this->assertNotEmpty($link->getAttribute('href'));
            $this->assertNotEmpty($link->getAttribute('aria-labelledby'));
            $this->assertNotEmpty($link->getAttribute('aria-describedby'));
            $this->assertSame(1, $xp->query('.//span[@id="'.$link->getAttribute('aria-labelledby').'" and contains(@class,"masters-link-title")]', $link)->length);
            $this->assertSame(1, $xp->query('.//span[@id="'.$link->getAttribute('aria-describedby').'" and contains(@class,"masters-link-description")]', $link)->length);
            $this->assertSame(1, $xp->query('.//span[@aria-hidden="true" and contains(@class,"masters-link-arrow")]', $link)->length);
            $this->assertSame(0, $xp->query('.//button | .//input', $link)->length);
        }
    }

    public function test_limited_access_does_not_leave_empty_navigation_groups(): void
    {
        [$viewer] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($viewer, ['customers.view']);
        $xp = $this->pageFor($viewer);

        $this->assertSame(1, $xp->query('//section[contains(@class,"masters-section")]')->length);
        $this->assertSame(1, $xp->query('//a[@data-masters-card="customers" and @href="'.route('customers.index').'"]')->length);
        $this->assertSame(1, $xp->query('//a[@data-masters-card]')->length);
        $this->assertSame(0, $xp->query('//h2[@id="masters-product-heading" or @id="masters-config-heading"]')->length);
    }

    public function test_navigation_destinations_are_items_in_their_named_group_lists(): void
    {
        [$owner] = $this->createRetailerTenant();
        $xp = $this->pageFor($owner);

        $this->assertSame(3, $xp->query('//section[contains(@class,"masters-section")]/ul[@role="list" and contains(@class,"masters-links")]')->length);
        $this->assertSame(6, $xp->query('//ul[contains(@class,"masters-links")]/li/a[@data-masters-card]')->length);
        $this->assertSame(0, $xp->query('//a[@data-masters-card and not(parent::li/parent::ul)]')->length);
    }

    public function test_empty_access_state_preserves_its_explanation_without_placeholder_cards(): void
    {
        [$viewer] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($viewer, []);
        $xp = $this->pageFor($viewer);

        $this->assertSame(1, $xp->query('//div[contains(@class,"masters-empty")]/p[contains(.,"No master records are available for your access.")]')->length);
        $this->assertSame(1, $xp->query('//div[contains(@class,"masters-empty")]/p[contains(.,"Ask your shop owner to grant access")]')->length);
        $this->assertSame(0, $xp->query('//a[@data-masters-card] | //section[contains(@class,"masters-section")]')->length);
    }
}
