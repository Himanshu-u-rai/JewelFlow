<?php

namespace App\Http\Middleware;

use App\Models\ShopPreferences;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetShopLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $defaultLocale = config('app.locale', 'en');
        $supportedLocales = array_keys(config('app.supported_locales', ['en' => 'English']));
        $locale = $defaultLocale;

        $user = Auth::user();

        if ($user && $user->shop_id) {
            $preferredLocale = ShopPreferences::query()
                ->where('shop_id', $user->shop_id)
                ->value('language');

            if ($preferredLocale && in_array($preferredLocale, $supportedLocales, true)) {
                $locale = $preferredLocale;
            }
        }

        app()->setLocale($locale);

        return $next($request);
    }
}

