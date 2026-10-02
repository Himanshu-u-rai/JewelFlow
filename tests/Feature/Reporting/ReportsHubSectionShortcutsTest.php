<?php

namespace Tests\Feature\Reporting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The reports hub is the one way into every report, and it is a long page: on
 * a phone its last section starts several screens down. A row of shortcuts at
 * the top jumps to each section.
 *
 * Which sections a shop sees depends on its edition (a section with no card
 * for that edition is not rendered). So the shortcuts and the sections must
 * come from the same list: every shortcut lands on a section that is on the
 * page, every section on the page has a shortcut, in the same order. A
 * shortcut to a section that was filtered out would be a link to nowhere.
 */
class ReportsHubSectionShortcutsTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
    }

    public function test_each_section_on_the_hub_has_one_shortcut_pointing_at_it(): void
    {
        foreach (['retailer', 'manufacturer'] as $edition) {
            [$owner] = $edition === 'retailer' ? $this->createRetailerTenant() : $this->createManufacturerTenant();
            $html = $this->actingAs($owner)->get(route('report.hub'))->assertOk()->getContent();

            preg_match_all('~<section class="rh-section" id="([^"]+)"~', $html, $sections);
            preg_match_all('~<a href="#([^"]+)" class="rh-jump-link"~', $html, $shortcuts);

            $this->assertGreaterThan(1, count($sections[1]), "{$edition}: the hub shows more than one section");
            $this->assertSame($sections[1], $shortcuts[1], "{$edition}: shortcuts and sections differ");
            $this->assertSame($sections[1], array_values(array_unique($sections[1])), "{$edition}: two sections share an id");

            // The shortcuts come before the first section, inside the page body.
            $this->assertLessThan(strpos($html, '<section class="rh-section"'), strpos($html, 'class="rh-jump"'), "{$edition}: shortcuts are not at the top");
        }
    }
}
