<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::back()->with('status', 'profile-updated');
    }

    /**
     * Deactivate the user's account (sets is_active = false, logs out).
     * Account data is preserved; the user cannot log back in until reactivated by an admin.
     */
    public function deactivate(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeactivation', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::guard('web')->logout();

        DB::table('users')
            ->where('id', $user->id)
            ->update(['is_active' => DB::raw('false')]);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/login')->with('status', 'Your account has been deactivated. Contact the shop owner to reactivate.');
    }
}
