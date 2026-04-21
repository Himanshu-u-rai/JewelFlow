<?php

namespace App\Http\Controllers;

use App\Models\CatalogPage;
use App\Models\CatalogWebsiteSettings;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CatalogWebsiteSettingsController extends Controller
{
    /**
     * Update catalog website settings (enable/disable, branding, visibility, etc.).
     */
    public function updateSettings(Request $request)
    {
        $shop = auth()->user()->shop;

        $validated = $request->validate([
            'is_enabled'          => 'boolean',
            'catalog_slug'        => [
                'nullable', 'string', 'max:80', 'alpha_dash',
                Rule::unique('shops', 'catalog_slug')->ignore($shop->id),
            ],
            'accent_color'        => 'nullable|string|max:20',
            'tagline'             => 'nullable|string|max:150',
            'hero_image'          => 'nullable|file|mimes:jpeg,png,webp|max:5120',
            'remove_hero_image'   => 'boolean',
            'show_prices'         => 'boolean',
            'show_weights'        => 'boolean',
            'show_huid'           => 'boolean',
            'meta_description'    => 'nullable|string|max:500',
            'social_whatsapp'     => 'nullable|string|max:20',
            'social_instagram'    => 'nullable|url|max:255',
            'social_facebook'     => 'nullable|url|max:255',
            'featured_categories' => 'nullable|array',
            'featured_categories.*' => 'string|max:100',
        ]);

        // Update catalog slug on the shop.
        if (! empty($validated['catalog_slug'])) {
            $shop->update(['catalog_slug' => $validated['catalog_slug']]);
        } elseif (empty($shop->catalog_slug)) {
            $shop->update(['catalog_slug' => Shop::generateUniqueCatalogSlug($shop->name, $shop->id)]);
        }

        $settings = CatalogWebsiteSettings::firstOrNew(['shop_id' => $shop->id]);

        // Hero image upload.
        if ($request->hasFile('hero_image')) {
            if ($settings->hero_image_path) {
                Storage::disk('public')->delete($settings->hero_image_path);
            }
            $validated['hero_image_path'] = $request->file('hero_image')->store('catalog-heroes', 'public');
        } elseif ($request->boolean('remove_hero_image') && $settings->hero_image_path) {
            Storage::disk('public')->delete($settings->hero_image_path);
            $validated['hero_image_path'] = null;
        }

        unset($validated['hero_image'], $validated['remove_hero_image'], $validated['catalog_slug']);

        $settings->fill($validated);
        $settings->save();

        return redirect()->route('settings.edit', ['tab' => 'website'])
            ->with('success', __('Catalog website settings updated.'));
    }

    /**
     * Create a new CMS page.
     */
    public function storePage(Request $request)
    {
        $validated = $request->validate([
            'title'   => 'required|string|max:255',
            'type'    => 'required|string|in:about,terms,contact,custom',
            'content' => 'nullable|string|max:65000',
        ]);

        $shop = auth()->user()->shop;
        $slug = $this->generateUniquePageSlug($shop->id, $validated['title']);

        CatalogPage::create([
            'shop_id' => $shop->id,
            'title'   => $validated['title'],
            'slug'    => $slug,
            'type'    => $validated['type'],
            'content' => $validated['content'] ?? '',
        ]);

        return redirect()->route('settings.edit', ['tab' => 'website'])
            ->with('success', __('Page created successfully.'));
    }

    /**
     * Update an existing CMS page.
     */
    public function updatePage(Request $request, CatalogPage $page)
    {
        $this->authorize('update', $page);

        $validated = $request->validate([
            'title'        => 'required|string|max:255',
            'content'      => 'nullable|string|max:65000',
            'is_published' => 'boolean',
        ]);

        $page->update($validated);

        return redirect()->route('settings.edit', ['tab' => 'website'])
            ->with('success', __('Page updated successfully.'));
    }

    /**
     * Delete a CMS page.
     */
    public function destroyPage(CatalogPage $page)
    {
        $this->authorize('delete', $page);

        $page->delete();

        return redirect()->route('settings.edit', ['tab' => 'website'])
            ->with('success', __('Page deleted.'));
    }

    private function generateUniquePageSlug(int $shopId, string $title): string
    {
        $base = Str::slug($title) ?: 'page';
        $slug = $base;
        $suffix = 1;

        while (CatalogPage::withoutTenant()->where('shop_id', $shopId)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
