<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Masters Hub — a permission- and edition-aware index of the existing
 * master-data screens (parties, product setup, related config).
 *
 * Additive navigation only: every card links to an already-registered named
 * route. Nothing is created, edited or deleted here — the hub owns no data.
 *
 * Each card mirrors the EXACT middleware of its destination route: the same
 * `can:` permission and the same `edition:` gate. A card renders only when both
 * match, so the hub never advertises a link the user would be 403'd from.
 */
class MastersController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $shop = $user->shop;

        $cards = collect([
            // ── Parties ──
            ['section' => 'parties', 'icon' => 'customers', 'title' => 'Customers',
             'description' => 'Buyers and account holders used across sales, billing and loyalty.',
             'route' => 'customers.index', 'can' => 'customers.view', 'editions' => ['retailer', 'manufacturer']],
            ['section' => 'parties', 'icon' => 'vendors', 'title' => 'Vendors / Suppliers',
             'description' => 'Suppliers you purchase stock and materials from.',
             'route' => 'vendors.index', 'can' => 'vendors.view', 'editions' => ['retailer']],
            ['section' => 'parties', 'icon' => 'karigars', 'title' => 'Karigars',
             'description' => 'Goldsmiths and job-work artisans used in manufacturing orders.',
             'route' => 'karigars.index', 'can' => 'karigar.view', 'editions' => ['retailer']],

            // ── Product setup ──
            ['section' => 'product', 'icon' => 'categories', 'title' => 'Categories & Subcategories',
             'description' => 'The classification tree that organises stock and design records.',
             'route' => 'categories.index', 'can' => 'inventory.view', 'editions' => []],
            ['section' => 'product', 'icon' => 'products', 'title' => 'Product / Design Master',
             'description' => 'Reusable design templates that physical stock items are created from.',
             'route' => 'products.index', 'can' => 'inventory.view', 'editions' => ['manufacturer']],

            // ── Related config ──
            ['section' => 'config', 'icon' => 'gst', 'title' => 'GST & Tax',
             'description' => 'Tax rates and GST categories applied during billing.',
             'route' => 'settings.edit', 'params' => ['tab' => 'gst'], 'can' => 'settings.view', 'editions' => []],
            // Discoverability shortcut into the existing Pricing Settings purity-profile
            // editor — Pricing Settings remains the single source of truth. This card
            // owns no data and adds no CRUD. Its gate mirrors the pricing entry point
            // EXACTLY (`$shop->isRetailer()` + `can:pricing.update`), so it renders only
            // when the user could already open that editor.
            ['section' => 'config', 'icon' => 'purity', 'title' => 'Metal Purity Profiles',
             'description' => 'Manage metal purity profiles and same-day overrides used by retailer pricing.',
             'route' => 'settings.edit', 'params' => ['tab' => 'pricing'], 'fragment' => 'purity-profiles',
             'can' => 'pricing.update', 'editions' => ['retailer']],
        ])->filter(function (array $card) use ($user, $shop) {
            $editionOk = empty($card['editions'])
                || ($shop && $shop->hasAnyEdition(...$card['editions']));

            return $editionOk && $user->can($card['can']);
        })->groupBy('section');

        return view('masters.index', ['cards' => $cards]);
    }
}
