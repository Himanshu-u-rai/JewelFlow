<?php

namespace Tests\Feature\Security;

use App\Services\PricingEngine;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The whole rotation proposed in docs/runbooks/app-key-rotation-proposal.md,
 * from the old key to the day it is retired. Nothing here rotates a real
 * key.
 *
 *   before      APP_KEY = old
 *   window      APP_KEY = new, APP_PREVIOUS_KEYS = old
 *   retirement  APP_KEY = new, no previous key
 */
class AppKeyRotationLifecycleTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private string $old;
    private string $new;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->old = 'base64:' . base64_encode(Encrypter::generateKey(config('app.cipher')));
        $this->new = 'base64:' . base64_encode(Encrypter::generateKey(config('app.cipher')));
        $this->useKey($this->old);
    }

    private function useKey(string $key, array $previous = []): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstances();
    }

    public function test_the_whole_rotation_from_the_old_key_to_its_retirement(): void
    {
        // ── before ──────────────────────────────────────────────────────────
        $cookie = Crypt::encryptString('session-id');
        $link = URL::temporarySignedRoute('reporting.exports.download', now()->addDays(7), ['export' => 1]);
        $quote = '{"total":"1000.00"}';
        $quoteSignature = hash_hmac('sha256', $quote, base64_decode(substr($this->old, 7)));

        // ── the window: new key, old key kept as previous ───────────────────
        $this->useKey($this->new, [$this->old]);

        $this->assertSame('session-id', Crypt::decryptString($cookie));
        $this->assertTrue(URL::hasValidSignature(Request::create($link)));
        $this->assertFalse(app(PricingEngine::class)->verify($quote, $quoteSignature), 'an open quote is made again; this is the stated cost');

        $cookieNew = Crypt::encryptString('session-id-2');
        $linkNew = URL::temporarySignedRoute('reporting.exports.download', now()->addDays(7), ['export' => 2]);
        $quoteNewSignature = hash_hmac('sha256', $quote, base64_decode(substr($this->new, 7)));

        // ── retirement: the old key is gone ─────────────────────────────────
        $this->useKey($this->new);

        // Everything made under the new key still works.
        $this->assertSame('session-id-2', Crypt::decryptString($cookieNew));
        $this->assertTrue(URL::hasValidSignature(Request::create($linkNew)));
        $this->assertTrue(app(PricingEngine::class)->verify($quote, $quoteNewSignature));

        // Nothing made under the old key is accepted any more.
        $this->assertFalse(URL::hasValidSignature(Request::create($link)));
        $this->assertFalse(app(PricingEngine::class)->verify($quote, $quoteSignature));
        try {
            Crypt::decryptString($cookie);
            $this->fail('a cookie sealed under the retired key was accepted');
        } catch (DecryptException) {
        }
    }
}
