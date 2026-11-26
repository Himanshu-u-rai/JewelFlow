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
        // and we always store emails in lowercase. Scope to the current realm.
        $credentials = [
            'email' => strtolower(trim($request->email)),
            'realm' => Realm::current($request),
        ];

        // Same reply whatever the outcome (sent, no such account, resend throttled), so the
        // page cannot be used to find out which email addresses have an account.
        Password::sendResetLink($credentials);

        return back()->with('status', __('If an account uses that email address, we have sent it a password reset link.'));
    }
}
