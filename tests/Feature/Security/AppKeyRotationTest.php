<?php

namespace Tests\Feature\Security;

use App\Services\PricingEngine;
use App\Services\ProductPromotionService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * What a rotation of APP_KEY keeps and what it breaks, measured rather than
 * assumed. Nothing here rotates anything: see
 * docs/runbooks/app-key-rotation-proposal.md, which rests on these results.
 */
class AppKeyRotationTest extends TestCase
{
    private string $old;
    private string $new;

    protected function setUp(): void
    {
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

    public function test_cookies_and_other_ciphertexts_survive_when_the_old_key_is_kept_as_previous(): void
    {
        $sealed = Crypt::encryptString('session-id');

        $this->useKey($this->new, [$this->old]);
        $this->assertSame('session-id', Crypt::decryptString($sealed));

        // Without the old key every signed-in browser is signed out at once.
        $this->useKey($this->new);
        $this->expectException(DecryptException::class);
        Crypt::decryptString($sealed);
    }

    public function test_signed_links_already_sent_survive_when_the_old_key_is_kept_as_previous(): void
    {
        $link = URL::temporarySignedRoute('reporting.exports.download', now()->addHour(), ['export' => 1]);

        $this->useKey($this->new, [$this->old]);
        $this->assertTrue(URL::hasValidSignature(Request::create($link)));

        $this->useKey($this->new);
        $this->assertFalse(URL::hasValidSignature(Request::create($link)));
    }

    public function test_a_pos_quote_signed_before_the_rotation_no_longer_verifies(): void
    {
        $json = '{"total":"1000.00"}';
        $signature = hash_hmac('sha256', $json, base64_decode(substr($this->old, 7)));
        $this->assertTrue(app(PricingEngine::class)->verify($json, $signature));

        // PricingEngine signs with the current key only: previous keys do not help.
        $this->useKey($this->new, [$this->old]);
        $this->assertFalse(app(PricingEngine::class)->verify($json, $signature));
    }

    public function test_a_recognition_proof_stored_before_the_rotation_no_longer_matches(): void
    {
        $proof = new ReflectionMethod(ProductPromotionService::class, 'proof');
        $identity = (object) ['id' => 1, 'shop_id' => 1, 'realm' => 'retail', 'role_id' => 1, 'password' => 'hash', 'created_at' => '2026-01-01 00:00:00'];

        $stored = $proof->invoke(app(ProductPromotionService::class), $identity);

        // The proof is an HMAC under the current key only. After a rotation an
        // unchanged owner looks like a changed one, and every recognition
        // between the two products would be treated as invalid.
        $this->useKey($this->new, [$this->old]);
        $this->assertNotSame($stored, $proof->invoke(app(ProductPromotionService::class), $identity));
    }
}
