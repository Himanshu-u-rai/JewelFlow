<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\IndianMobileRule;
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
     * Show registration form.
     */
    public function create(Request $request): View
    {
        return view('auth.register');
    }

    /**
     * Handle registration
     */
    public function store(Request $request): RedirectResponse
    {
        // Every account is an ERP account. Mobile must be unique within the realm.
        $realm = Realm::current($request);

        $request->validate([
            'mobile_number' => ['required', 'string', new IndianMobileRule(), Realm::uniqueMobileRule($realm)],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
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

            return redirect()->route('shops.choose-type');

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
