<?php

namespace Tests\Feature;

use App\Models\CatalogWebsiteSettings;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The public catalog homepage must actually RENDER the uploaded hero banner.
 * The banner was saving correctly in Settings but the home view never used
 * $catalogSettings->hero_image_path, so the storefront hero stayed a plain
 * coloured block. These tests lock the hero image into the rendered output.
 */
class CatalogWebsiteHeroTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function renderHome(CatalogWebsiteSettings $settings, $shop): string
    {
        // The home view links to catalog.website.products, which needs a slug.
        if (empty($shop->catalog_slug)) {
            $shop->forceFill(['catalog_slug' => 'test-shop'])->save();
        }

        // Share exactly what ResolveCatalogShop middleware provides to the view.
        View::share('shop', $shop);
        View::share('catalogSettings', $settings);
        View::share('navCategories', collect());
        View::share('catalogPages', collect());

        return View::make('public.catalog.home', [
            'categoryData'    => collect(),
            'recentItems'     => collect(),
            'recentImageUrls' => [],
        ])->render();
    }

    public function test_home_renders_the_hero_banner_when_one_is_uploaded(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        $html = TenantContext::runFor($shop->id, function () use ($shop) {
            $settings = CatalogWebsiteSettings::create([
                'shop_id'         => $shop->id,
                'is_enabled'      => true,
                'hero_style'      => 'image',
                'hero_image_path' => 'catalog-heroes/test-banner.webp',
            ]);

            return $this->renderHome($settings, $shop);
        });

        // The hero <section> carries the image modifier + the background-image URL.
        $this->assertStringContainsString('class="hero hero--image"', $html);
        $this->assertStringContainsString(
            'background-image: url(' . asset('storage/catalog-heroes/test-banner.webp') . ')',
            $html
        );
        // Quotes must NOT be HTML-escaped into the CSS (would break url()).
        $this->assertStringNotContainsString('url(&#039;', $html);
    }

    public function test_home_renders_a_solid_colour_hero(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        $html = TenantContext::runFor($shop->id, function () use ($shop) {
            $settings = CatalogWebsiteSettings::create([
                'shop_id'       => $shop->id,
                'is_enabled'    => true,
                'hero_style'    => 'color',
                'hero_bg_color' => '#123456',
            ]);

            return $this->renderHome($settings, $shop);
        });

        $this->assertStringContainsString('class="hero hero--color"', $html);
        $this->assertStringContainsString('background: #123456;', $html);
    }

    public function test_solid_colour_hero_falls_back_to_accent_when_unset(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        $html = TenantContext::runFor($shop->id, function () use ($shop) {
            $settings = CatalogWebsiteSettings::create([
                'shop_id'       => $shop->id,
                'is_enabled'    => true,
                'hero_style'    => 'color',
                'hero_bg_color' => null,
                'accent_color'  => '#abcdef',
            ]);

            return $this->renderHome($settings, $shop);
        });

        $this->assertStringContainsString('class="hero hero--color"', $html);
        $this->assertStringContainsString('background: #abcdef;', $html);
    }

    public function test_image_style_with_no_upload_degrades_to_plain(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        $html = TenantContext::runFor($shop->id, function () use ($shop) {
            $settings = CatalogWebsiteSettings::create([
                'shop_id'         => $shop->id,
                'is_enabled'      => true,
                'hero_style'      => 'image',
                'hero_image_path' => null,
            ]);

            return $this->renderHome($settings, $shop);
        });

        // No banner uploaded → the image style degrades to the plain hero.
        $this->assertStringContainsString('class="hero "', $html);
        $this->assertStringNotContainsString('class="hero hero--image"', $html);
        $this->assertStringNotContainsString('background-image: url', $html);
    }

    public function test_home_keeps_the_plain_hero_when_style_is_plain(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        $html = TenantContext::runFor($shop->id, function () use ($shop) {
            $settings = CatalogWebsiteSettings::create([
                'shop_id'         => $shop->id,
                'is_enabled'      => true,
                'hero_style'      => 'plain',
                'hero_image_path' => 'catalog-heroes/ignored.webp',
            ]);

            return $this->renderHome($settings, $shop);
        });

        // Plain explicitly chosen → no image even though a banner exists.
        $this->assertStringContainsString('class="hero "', $html);
        $this->assertStringNotContainsString('class="hero hero--image"', $html);
        $this->assertStringNotContainsString('class="hero hero--color"', $html);
        $this->assertStringNotContainsString('background-image: url', $html);
    }
}
