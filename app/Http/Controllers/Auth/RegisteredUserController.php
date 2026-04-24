<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Show registration form
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle registration
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'mobile_number' => ['required', 'string', 'digits:10', 'unique:users,mobile_number'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'mobile_number.digits' => 'Mobile number must be exactly 10 digits.',
        ]);

        DB::beginTransaction();

        try {
            // Create bare-bones user (exactly as you wanted)
            $user = User::create([
                'mobile_number' => $request->mobile_number,
                'password' => Hash::make($request->password),
            ]);

            DB::commit();

            event(new Registered($user));

            // Log in the user immediately
            Auth::login($user);

            // SEND THEM STRAIGHT TO SHOP CREATION
            return redirect()->route('shops.choose-type');

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
