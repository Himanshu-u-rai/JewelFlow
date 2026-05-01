<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Web\WebSessionSeatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, WebSessionSeatService $seatService): RedirectResponse
    {
        try {
            $request->authenticate();
        } catch (ValidationException $e) {
            return back()
                ->withErrors($e->errors())
                ->withInput($request->only('mobile_number', 'remember'));
        }

        $request->session()->regenerate();

        $shop = auth()->user()->shop;
        $legacyUnpaidShop = $shop
            && $shop->subscriptions()->doesntExist()
            && $shop->suspension_reason === 'Legacy shop missing subscription record';

        if (!auth()->user()->is_active) {
            Auth::guard('web')->logout();
            $request->session()->regenerate();
            $request->session()->regenerateToken();

            return redirect('/login')
                ->with('login_modal', 'account_inactive')
                ->withErrors([
                    'mobile_number' => 'Your account is inactive.',
                ]);
        }

        if ($shop && !$shop->is_active && !$legacyUnpaidShop) {
            Auth::guard('web')->logout();
            $request->session()->regenerate();
            $request->session()->regenerateToken();

            return redirect('/login')
                ->with('login_modal', 'shop_deactivated')
                ->withErrors([
                    'mobile_number' => 'Your shop is currently deactivated.',
                ]);
        }

        if ($legacyUnpaidShop) {
            return redirect()->route('subscription.plans')->with('error', 'Please choose a plan and complete payment to reactivate your shop.');
        }

        // After successful login, redirect based on whether user has a shop
        if (auth()->user()->shop_id === null) {
            return redirect()->route('shops.create');
        }

        // Enforce concurrent web session seat limit (owner is always exempt).
        if ($shop) {
            $seat = $seatService->evaluate($shop, auth()->user());
            if (! $seat['allowed']) {
                Auth::guard('web')->logout();
                $request->session()->regenerate();
                $request->session()->regenerateToken();

                $message = $seat['reason_code'] === 'already_on_mobile'
                    ? 'This account is already logged in on the mobile app. Log out of the mobile app first, then try again.'
                    : "All {$seat['session_limit']} web seats are in use. Ask an active user to log out, then try again.";

                return redirect('/login')
                    ->with('login_modal', 'web_session_limit_reached')
                    ->withErrors(['mobile_number' => $message]);
            }
        }

        // Dhiran-only shops land on the Dhiran dashboard.
        if ($shop && $shop->hasEdition('dhiran') && ! $shop->hasAnyEdition('retailer', 'manufacturer')) {
            return redirect()->route('dhiran.dashboard');
        }

        return redirect()->route('dashboard');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
