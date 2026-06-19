<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Realm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Normalize to lowercase — PostgreSQL is case-sensitive,
        // and we always store emails in lowercase. Scope to the current realm so
        // a reset on dhiran.* can only ever resolve a Dhiran account (and vice
        // versa) — the same email may exist in both realms.
        $credentials = [
            'email' => strtolower(trim($request->email)),
            'realm' => Realm::current($request),
        ];

        $status = Password::sendResetLink($credentials);

        return $status == Password::RESET_LINK_SENT
                    ? back()->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
