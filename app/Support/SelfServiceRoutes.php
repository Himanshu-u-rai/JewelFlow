<?php

namespace App\Support;

/**
 * Routes a signed-in user may always reach, whatever state the shop or its
 * subscription is in. Signing out must always end the session (a refused
 * sign-out leaves a live token behind), and an app must be able to check its
 * own session to tell "closed shop" from "signed out". The subscription,
 * account and closed-shop gates all let these through.
 */
final class SelfServiceRoutes
{
    /** Ending one's own session, on the web and the app. */
    public const SIGN_OUT = ['logout', 'mobile.auth.logout', 'mobile.v1.device.push_token.destroy'];

    /** Reading one's own session and locking the phone for a handoff. */
    public const OWN_SESSION = [
        'mobile.auth.me', 'mobile.v1.sessions.me', 'mobile.v1.sessions.lock', 'mobile.v1.sessions.unlock',
        'mobile.v1.device.push_token.store',
    ];
}
