<?php

namespace Tests\Feature\Mobile\V1;

use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * M2 — Canonical {data, meta, errors} envelope contract.
 *
 * Every successful response, every error, every validation failure on
 * /api/mobile/v1/* lands in the same shape. Mobile clients use one
 * deserializer and one error-handler.
 *
 * This test pins:
 *   - data is the payload (raw object, never a paginator dump)
 *   - meta has request_id, server_time (ISO-8601), api_version, registry_version
 *   - errors is an array of {code, field?, message} objects on failure
 *   - request_id round-trips via X-Request-Id header
 *   - already-enveloped bodies (idempotency replay) are passed through
 */
class EnvelopeContractTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        MetalRegistry::clearShopCache();
        parent::tearDown();
    }

    public function test_success_response_has_canonical_envelope(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/registry/materials');
        $response->assertOk();

        $body = $response->json();
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
        $this->assertArrayHasKey('errors', $body);
        $this->assertSame([], $body['errors']);

        $meta = $body['meta'];
        $this->assertArrayHasKey('request_id', $meta);
        $this->assertArrayHasKey('server_time', $meta);
        $this->assertArrayHasKey('api_version', $meta);
        $this->assertArrayHasKey('registry_version', $meta);
        $this->assertSame('1', $meta['api_version']);
        $this->assertSame(MetalRegistry::registryVersion(), $meta['registry_version']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $meta['server_time']
        );
    }

    public function test_request_id_round_trips_via_header(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        $clientRequestId = 'test-' . str_repeat('a', 16);
        $response = $this->withHeaders(['X-Request-Id' => $clientRequestId])
            ->getJson('/api/mobile/v1/registry/materials');

        $this->assertSame($clientRequestId, $response->json('meta.request_id'));
        $this->assertSame($clientRequestId, $response->headers->get('X-Request-Id'));
    }

    public function test_unauthenticated_response_uses_envelope_with_errors(): void
    {
        $response = $this->getJson('/api/mobile/v1/registry/materials');
        $response->assertStatus(401);

        $body = $response->json();
        $this->assertArrayHasKey('data', $body);
        $this->assertNull($body['data']);
        $this->assertArrayHasKey('meta', $body);
        $this->assertArrayHasKey('errors', $body);
        $this->assertNotEmpty($body['errors']);

        $err = $body['errors'][0];
        $this->assertArrayHasKey('code', $err);
        $this->assertArrayHasKey('message', $err);
        $this->assertSame('unauthorized', $err['code']);
    }

    public function test_validation_errors_are_normalised_to_canonical_shape(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        // Register a transient route that triggers a validation error so we
        // can verify the envelope reshapes Laravel's default error payload.
        Route::middleware(['auth:sanctum', 'tenant', 'subscription.active', 'account.active', 'shop.exists', 'rate.shop:600,1', 'mobile.envelope'])
            ->post('/api/mobile/v1/__test/validate', function (\Illuminate\Http\Request $r) {
                $r->validate([
                    'metal_type' => 'required|string',
                    'purity'     => 'required|numeric',
                ]);
                return ['ok' => true];
            });

        $response = $this->postJson('/api/mobile/v1/__test/validate', []);
        $response->assertStatus(422);

        $body = $response->json();
        $this->assertNull($body['data']);
        $this->assertIsArray($body['errors']);
        $this->assertGreaterThanOrEqual(2, count($body['errors']));

        $codes  = array_column($body['errors'], 'code');
        $fields = array_column($body['errors'], 'field');
        $this->assertContains('metal_type', $fields);
        $this->assertContains('purity', $fields);
        foreach ($codes as $c) {
            $this->assertStringStartsWith('validation.', $c);
        }
    }

    public function test_already_enveloped_body_is_passed_through_with_meta_restamped(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        // Simulates the idempotency-replay path: controller returns a body
        // already shaped as the envelope. The middleware must respect it
        // and only top up the meta block.
        Route::middleware(['auth:sanctum', 'tenant', 'subscription.active', 'account.active', 'shop.exists', 'rate.shop:600,1', 'mobile.envelope'])
            ->get('/api/mobile/v1/__test/passthrough', function () {
                return response()->json([
                    'data'   => ['echo' => 'preserved'],
                    'meta'   => ['custom' => 'tag'],
                    'errors' => [],
                ]);
            });

        $response = $this->getJson('/api/mobile/v1/__test/passthrough');
        $response->assertOk();

        $body = $response->json();
        $this->assertSame(['echo' => 'preserved'], $body['data']);
        $this->assertSame([], $body['errors']);
        $this->assertSame('tag', $body['meta']['custom']);
        // Meta has been topped up by the envelope.
        $this->assertArrayHasKey('request_id', $body['meta']);
        $this->assertArrayHasKey('registry_version', $body['meta']);
    }
}
