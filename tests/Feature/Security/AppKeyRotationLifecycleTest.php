<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\PricingEngine;
use App\Services\ProductPromotionService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The whole rotation proposed in docs/runbooks/app-key-rotation-proposal.md,
 * from the old key to the day it is retired. Nothing here rotates a real
 * key.
 *
 *   before      APP_KEY = old
 *   window      APP_KEY = new, APP_PREVIOUS_KEYS = old, proofs re-stamped
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

    /** Two owners, one per product, and a recognition between them made under the key now in use. */
    private function recognition(): array
    {
        [$retail] = $this->createRetailerTenant();
        [$dhiran] = $this->createRetailerTenant();
        $dhiran->forceFill(['realm' => User::REALM_DHIRAN])->save();

        $id = (string) Str::uuid();
        DB::table('product_recognitions')->insert([
            'id' => $id, 'environment' => app()->environment(), 'request_id' => (string) Str::uuid(),
            'erp_user_id' => $retail->id, 'erp_shop_id' => $retail->shop_id, 'erp_proof' => $this->proofOf($retail), 'erp_approved_at' => now(),
            'dhiran_user_id' => $dhiran->id, 'dhiran_shop_id' => $dhiran->shop_id, 'dhiran_proof' => $this->proofOf($dhiran), 'dhiran_approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$retail->fresh(), $dhiran->fresh(), $id];
    }

    private function proofOf(User $user): string
    {
        $service = app(ProductPromotionService::class);
        $identity = (new ReflectionMethod($service, 'identity'))->invoke($service, $user->id, $user->shop_id, $user->realm);

        return (new ReflectionMethod($service, 'proof'))->invoke($service, $identity);
    }

    private function honoured(User $owner): int
    {
        return app(ProductPromotionService::class)->recognitions($owner)->count();
    }

    public function test_the_whole_rotation_from_the_old_key_to_its_retirement(): void
    {
        // ── before ──────────────────────────────────────────────────────────
        [$retail, $dhiran, $id] = $this->recognition();
        $cookie = Crypt::encryptString('session-id');
        $link = URL::temporarySignedRoute('reporting.exports.download', now()->addDays(7), ['export' => 1]);
        $quote = '{"total":"1000.00"}';
        $quoteSignature = hash_hmac('sha256', $quote, base64_decode(substr($this->old, 7)));
        $this->assertSame(1, $this->honoured($retail));

        // ── the window: new key, old key kept as previous, proofs re-stamped ─
        $this->useKey($this->new, [$this->old]);
        $this->artisan('promotion:restamp-proofs')->expectsOutputToContain('2 would be re-stamped')->assertExitCode(1);
        $this->artisan('promotion:restamp-proofs', ['--write' => true])->expectsOutputToContain('2 re-stamped')->assertExitCode(0);
        $this->artisan('promotion:restamp-proofs')->expectsOutputToContain('0 would be re-stamped')->assertExitCode(0);

        $this->assertSame(1, $this->honoured($retail), 'the recognition did not survive the rotation');
        $this->assertSame(1, $this->honoured($dhiran));
        $this->assertNull(DB::table('product_recognitions')->where('id', $id)->value('revoked_at'));
        $this->assertSame('session-id', Crypt::decryptString($cookie));
        $this->assertTrue(URL::hasValidSignature(Request::create($link)));
        $this->assertFalse(app(PricingEngine::class)->verify($quote, $quoteSignature), 'an open quote is made again; this is the stated cost');

        $cookieNew = Crypt::encryptString('session-id-2');
        $linkNew = URL::temporarySignedRoute('reporting.exports.download', now()->addDays(7), ['export' => 2]);
        $quoteNewSignature = hash_hmac('sha256', $quote, base64_decode(substr($this->new, 7)));

        // ── retirement: the old key is gone ─────────────────────────────────
        $this->useKey($this->new);

        // Everything made under the new key, and the re-stamped recognition, still works.
        $this->assertSame(1, $this->honoured($retail));
        $this->assertSame(1, $this->honoured($dhiran));
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

    public function test_without_the_re_stamp_the_rotation_revokes_the_recognition_for_good(): void
    {
        [$retail, , $id] = $this->recognition();

        $this->useKey($this->new, [$this->old]);

        // The first page that reads it finds a proof that no longer matches and revokes it.
        $this->assertSame(0, $this->honoured($retail));
        $this->assertNotNull(DB::table('product_recognitions')->where('id', $id)->value('revoked_at'));

        // Putting the old key back does not bring it back.
        $this->useKey($this->old);
        $this->assertSame(0, $this->honoured($retail));
    }

    public function test_the_re_stamp_does_not_revive_a_proof_that_was_already_invalid(): void
    {
        [$retail, $dhiran, $id] = $this->recognition();

        // The Dhiran owner changed password after confirming: that proof is stale under ANY key.
        DB::table('users')->where('id', $dhiran->id)->update(['password' => Hash::make('another-password')]);

        $this->useKey($this->new, [$this->old]);
        $this->artisan('promotion:restamp-proofs', ['--write' => true])->expectsOutputToContain('1 re-stamped')->expectsOutputToContain('1 already invalid')->assertExitCode(0);

        $this->assertSame(0, $this->honoured($retail->fresh()), 'a recognition whose owner changed was laundered by the re-stamp');
        $this->assertNotNull(DB::table('product_recognitions')->where('id', $id)->value('revoked_at'));
    }

    public function test_the_check_cannot_be_run_once_the_old_key_has_been_removed(): void
    {
        $this->recognition();

        // No previous key configured: there is nothing to compare against, and saying "0 left" would be a lie.
        $this->useKey($this->new);
        $this->artisan('promotion:restamp-proofs')->assertExitCode(2);
    }
}
