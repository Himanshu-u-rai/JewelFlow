<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The Stock page's pricing-alert panel draws its rows, its "Loading…" line and
 * its empty state from Alpine templates. Turbo cached the page with those
 * drawn nodes in it. On Back, Alpine found the old rows with no list around
 * them and threw "item is not defined" for every binding in every row (48
 * errors for 12 alerts), and then drew the list again beside them: the panel
 * showed every alert twice, the old copies dead.
 *
 * Whatever a template draws is marked temporary, so Turbo drops it before it
 * caches the page and the page comes back with only the templates.
 *
 * Two smaller things the same investigation found, pinned here as well:
 * `x-init="init()"` ran the component's init a second time (Alpine calls it
 * by itself), which asked the server for the list twice; and the panel's
 * Escape handler ran close() whether or not the panel was open.
 *
 * A browser showed the failure and the fix. This pins the markup they rest on.
 */
class StockPricingAlertPanelRevisitTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
    }

    public function test_what_the_panel_draws_is_left_out_of_the_cached_page(): void
    {
        $panel = $this->panel();

        preg_match_all('~<template x-(?:for|if)="[^"]*"[^>]*>\s*<[a-z]+\b([^>]*)>~', $panel, $drawn);

        $this->assertCount(3, $drawn[1], 'the panel has three templates: loading, empty, and the rows');
        foreach ($drawn[1] as $attributes) {
            $this->assertStringContainsString('data-turbo-temporary', $attributes,
                'a template draws a node that Turbo would cache and Alpine would find orphaned on Back');
        }
    }

    public function test_the_panel_starts_once_and_only_an_open_panel_answers_escape(): void
    {
        $panel = $this->panel();

        $this->assertStringNotContainsString('x-init="init()"', $panel, 'Alpine already calls init(): a second call fetches the list twice');
        $this->assertStringContainsString('@keydown.escape.window="isOpen && close()"', $panel);
    }

    /** The panel's markup on a retailer's Stock page that has one item needing a price review. */
    private function panel(): string
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->createItem($shop->id, null, ['pricing_review_required' => true, 'pricing_review_notes' => 'No purity profile']);

        $html = $this->actingAs($owner)->get(route('inventory.items.index'))->assertOk()->getContent();

        $start = strpos($html, 'id="pricing-alert-drawer"');
        $this->assertNotFalse($start, 'the Stock page has no pricing-alert panel');
        $end = strpos($html, 'function pricingAlertDrawer', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }
}
