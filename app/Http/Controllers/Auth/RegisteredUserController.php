<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Realm;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Show registration form — Dhiran-branded on the dhiran.* host, ERP otherwise.
     */
    public function create(Request $request): View
    {
        return Realm::current($request) === Realm::DHIRAN
            ? view('auth.dhiran.register')
            : view('auth.register');
    }

    /**
     * Handle registration
     */
    public function store(Request $request): RedirectResponse
    {
        // The realm is determined by the host: registering on dhiran.* creates a
        // Dhiran account; the main domain creates an ERP account. Mobile must be
        // unique WITHIN the realm (the same phone may exist once per realm).
        $realm = Realm::current($request);

        $request->validate([
            'mobile_number' => ['required', 'string', 'digits:10', Realm::uniqueMobileRule($realm)],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'mobile_number.digits' => 'Mobile number must be exactly 10 digits.',
        ]);

        DB::beginTransaction();

        try {
            // Create bare-bones user in this realm.
            $user = User::create([
                'mobile_number' => $request->mobile_number,
                'password' => Hash::make($request->password),
                'realm' => $realm,
            ]);

            DB::commit();

            event(new Registered($user));

            // Log in the user immediately
            Auth::login($user);

            // Dhiran accounts go to the Dhiran area (its dashboard shows the
            // activation/onboarding placeholder until a Dhiran shop exists);
            // ERP accounts keep the existing shop-type onboarding.
            return $realm === Realm::DHIRAN
                ? redirect()->route('dhiran.dashboard')
                : redirect()->route('shops.choose-type');

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
