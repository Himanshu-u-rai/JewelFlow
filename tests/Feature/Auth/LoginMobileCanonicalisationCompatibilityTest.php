<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\CanonicaliseMobileInput;
use App\Models\User;
use App\Support\Mobile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CORRECTION OF A DEPLOYMENT-RISK CLAIM, PINNED AS BEHAVIOUR.
 *
 * A release note asserted that appending CanonicaliseMobileInput to the web and
 * api middleware groups was a login-lockout vector, and that any stored
 * non-canonical users.mobile_number was a "hard stop" because the middleware
 * would normalise the typed value and no longer match that row.
 *
 * That is wrong in both directions, and the two mistakes are worth naming
 * separately because they fail for different reasons:
 *
 *  1. A non-canonical STORED value was already unreachable BEFORE this release.
 *     LoginRequest has always validated 'mobile_number' => digits:10 and then
 *     looked the user up with an EXACT match. A row holding '+919812300099' or
 *     '09812300099' cannot be produced by any input that passes digits:10, so
 *     nobody could log into it yesterday either. The release does not create
 *     that lockout; it inherits it. A count of such rows is a pre-existing data
 *     problem, not a deployment gate.
 *
 *  2. The lockout the claim actually described — a stored ten-digit number the
 *     canonical PATTERN rejects — does not happen, because the middleware only
 *     rewrites a key when Mobile::normalize() returns NON-NULL. Legacy numbers
 *     starting 1-5 normalise to null and are therefore passed through exactly as
 *     typed. That null-guard is load-bearing: without it, LoginRequest's own
 *     docblock explains, those accounts would be locked out of their own data.
 *
 * What the middleware does do at the login boundary is WIDEN it: formatted input
 * that digits:10 used to reject now canonicalises and matches. That is a strict
 * improvement, and it is asserted here too.
 *
 * Each case is run BOTH ways in a single test — with the middleware and with it
 * disabled via withoutMiddleware() — so "old vs new" is measured rather than
 * reasoned about.
 */
class LoginMobileCanonicalisationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // CSRF and throttle off, matching AuthenticationTest. The canonicalisation
        // middleware is deliberately NOT disabled here — individual tests opt out
        // of it to measure the pre-release behaviour.
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    /**
     * Writes mobile_number straight to the column, bypassing the
     * CanonicalisesMobileNumbers mutator, which would otherwise canonicalise the
     * very legacy spelling a fixture is trying to reproduce.
     */
    private function userWithStoredMobile(string $stored): User
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        DB::table('users')->where('id', $user->id)->update(['mobile_number' => $stored]);

        return $user->fresh();
    }

    /**
     * Toggles the middleware BOTH ways on every call, deliberately.
     *
     * withoutMiddleware() is not scoped to the next request: it binds a
     * pass-through instance into the container that survives for the rest of
     * the test method. A helper that only ever disables would leave the
     * middleware off for the second leg too, and every "before vs after"
     * assertion below would silently be measuring "before" twice. Only case 2
     * expects different outcomes from its two legs, so it would be the only
     * test able to notice — which is exactly how this was caught.
     */
    private function attemptLogin(string $typed, bool $withCanonicalisation): void
    {
        $withCanonicalisation
            ? $this->withMiddleware(CanonicaliseMobileInput::class)
            : $this->withoutMiddleware(CanonicaliseMobileInput::class);

        $this->post('/login', [
            'mobile_number' => $typed,
            'password' => 'password',
            'otp_verified' => true,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════
    // Case 1 — an ordinary canonical account, typed canonically
    // ════════════════════════════════════════════════════════════════════

    public function test_a_canonical_ten_digit_account_logs_in_identically_with_and_without_the_middleware(): void
    {
        $this->userWithStoredMobile('9812300099');

        // Baseline: this account could log in before the release.
        $this->attemptLogin('9812300099', withCanonicalisation: false);
        $this->assertAuthenticated();

        $this->post('/logout');
        $this->assertGuest();

        // …and must still log in after it.
        $this->attemptLogin('9812300099', withCanonicalisation: true);
        $this->assertAuthenticated();
    }

    // ════════════════════════════════════════════════════════════════════
    // Case 2 — the same account, typed with formatting. This is the WIDENING.
    // ════════════════════════════════════════════════════════════════════

    public function test_formatted_input_is_rejected_before_the_release_and_accepted_after(): void
    {
        $this->userWithStoredMobile('9812300099');

        // Before: '+91 98123-00099' is not ten digits, so digits:10 refuses it
        // and the lookup is never reached.
        // Formatted input used to fail validation outright.
        $this->attemptLogin('+91 98123-00099', withCanonicalisation: false);
        $this->assertGuest();

        // After: canonicalised to the stored spelling before validation runs.
        // The middleware WIDENS what a user may type. Nothing narrows.
        $this->attemptLogin('+91 98123-00099', withCanonicalisation: true);
        $this->assertAuthenticated();
    }

    // ════════════════════════════════════════════════════════════════════
    // Case 3 — THE ONE THE CLAIM WAS ABOUT
    //
    // A legacy ten-digit number starting 1-5. Mobile::PATTERN is /^[6-9]\d{9}$/,
    // so normalize() returns null for it. If the middleware rewrote
    // unconditionally this account would lose its login on deploy; because it
    // only rewrites non-null results, it does not.
    // ════════════════════════════════════════════════════════════════════

    public function test_a_legacy_number_the_canonical_pattern_rejects_still_logs_in(): void
    {
        $legacy = '1234567890';

        $this->assertNull(
            Mobile::normalize($legacy),
            'Fixture guard: this number must be one the canonical pattern refuses, or the test proves nothing.'
        );

        $this->userWithStoredMobile($legacy);

        // Baseline: digits:10 accepts it and the exact lookup matches.
        $this->attemptLogin($legacy, withCanonicalisation: false);
        $this->assertAuthenticated();

        $this->post('/logout');
        $this->assertGuest();

        // REGRESSION GUARD: the middleware must pass through what it cannot
        // canonicalise. Rewriting unconditionally would lock this account out
        // of its own shop.
        $this->attemptLogin($legacy, withCanonicalisation: true);
        $this->assertAuthenticated();
    }

    // ════════════════════════════════════════════════════════════════════
    // Case 4 — a non-canonical STORED value: unreachable either way
    // ════════════════════════════════════════════════════════════════════

    public function test_a_non_canonical_stored_number_was_already_unreachable_before_the_release(): void
    {
        $this->userWithStoredMobile('+919812300099');

        // Typing the stored spelling: eleven characters plus punctuation, so
        // digits:10 refuses it before the release…
        $this->attemptLogin('+919812300099', withCanonicalisation: false);
        $this->assertGuest();

        // …and typing the canonical form finds no row, because the lookup is an
        // exact match against a column that does not hold that string.
        // Pre-existing condition: this row was already unreachable.
        $this->attemptLogin('9812300099', withCanonicalisation: false);
        $this->assertGuest();

        // After the release, both are still refused — the release neither caused
        // this nor fixed it.
        $this->attemptLogin('+919812300099', withCanonicalisation: true);
        $this->assertGuest();

        // Unchanged by the release. Not a deployment gate; a data-repair question.
        $this->attemptLogin('9812300099', withCanonicalisation: true);
        $this->assertGuest();
    }

    // ════════════════════════════════════════════════════════════════════
    // The invariant behind case 4, stated directly
    // ════════════════════════════════════════════════════════════════════

    public function test_no_input_that_passes_validation_can_ever_match_a_non_canonical_row(): void
    {
        // digits:10 admits exactly the ten-digit strings. The lookup is an exact
        // match. So the set of reachable stored values is precisely the set of
        // ten-digit strings — with or without canonicalisation, which can only
        // map an input INTO that set, never out of it.
        foreach (['+919812300099', '09812300099', '98123 00099', '9812-300099', ''] as $stored) {
            $this->assertNotSame(
                10,
                strlen($stored),
                "Fixture guard: '{$stored}' must not itself be ten characters."
            );
        }

        foreach (['9812300099', '+91 98123 00099', '09812300099', '1234567890'] as $typed) {
            $canonical = Mobile::normalize($typed) ?? $typed;

            $this->assertMatchesRegularExpression(
                '/^.{10}$/',
                $canonical,
                "After canonicalisation '{$typed}' must be a ten-character lookup key or fail validation."
            );
        }
    }
}
