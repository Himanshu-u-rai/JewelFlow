<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Restoration M16 (audit TURBO1/TURBO2/TURBO3): forms inside the
 * <turbo-frame id="settings-content"> that redirect to full pages need
 * data-turbo-frame="_top", or the success flash (which lives in <head>) never
 * shows and the logout form yields Turbo "Content missing". This guards the
 * core settings forms + the General-tab logout + staff Remove/Recover.
 */
class SettingsTurboFrameTest extends TestCase
{
    private string $blade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blade = file_get_contents(resource_path('views/settings.blade.php'));
    }

    /** @dataProvider redirectingForms */
    public function test_redirecting_form_has_turbo_top(string $action): void
    {
        // The form's opening tag (action + data-turbo-frame="_top") must co-occur.
        $this->assertMatchesRegularExpression(
            '/action="\{\{ route\(\'' . preg_quote($action, '/') . '\'.*?\) \}\}"[^>]*data-turbo-frame="_top"/s',
            $this->blade,
            "the {$action} form must carry data-turbo-frame=\"_top\""
        );
    }

    public static function redirectingForms(): array
    {
        return [
            'logout'        => ['logout'],
            'shop'          => ['settings.update.shop'],
            'billing'       => ['settings.update.billing'],
            'preferences'   => ['settings.update.preferences'],
            'return-policy' => ['settings.update.return-policy'],
            'role'          => ['settings.update.role'],
            'staff reactivate' => ['staff.reactivate'],
            'staff destroy'    => ['staff.destroy'],
        ];
    }

    /**
     * The Catalog Website tab is a partial rendered INSIDE the settings-content
     * frame. Its banner-upload + CMS page forms all redirect to a full page, so
     * each must carry data-turbo-frame="_top" — otherwise the hero-image upload
     * and page create/update/delete yield Turbo "Content missing".
     *
     * @dataProvider websiteTabForms
     */
    public function test_website_tab_redirecting_form_has_turbo_top(string $action): void
    {
        $partial = file_get_contents(resource_path('views/partials/settings/website-tab.blade.php'));

        $this->assertMatchesRegularExpression(
            '/action="\{\{ route\(\'' . preg_quote($action, '/') . '\'.*?\) \}\}"[^>]*data-turbo-frame="_top"/s',
            $partial,
            "the {$action} form (website tab) must carry data-turbo-frame=\"_top\""
        );
    }

    public static function websiteTabForms(): array
    {
        return [
            'catalog-website settings' => ['settings.update.catalog-website'],
            'page create'              => ['settings.catalog-pages.store'],
            'page update'              => ['settings.catalog-pages.update'],
            'page delete'              => ['settings.catalog-pages.destroy'],
        ];
    }
}
