<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_quotes', function (Blueprint $table) {
            // Surrogate PK kept separate from the public ULID so we never
            // leak monotonic row IDs in URLs / responses.
            $table->id();

            // Public identifier used in API responses and as the body field
            // that /pos/sell consumes. ULID is 26 chars Crockford base32.
            $table->char('quote_id', 26)->unique();

            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // retailer | manufacturer | exchange | repair — application-validated
            $table->string('mode', 20)->index();

            // web | mobile — for telemetry; cardinality too low to bother indexing
            $table->string('client', 10)->default('web');

            // Exact QuoteInput payload so we can replay / debug a stale quote.
            $table->json('input_payload');

            // The canonical JSON bytes that were signed. MUST be stored as the
            // exact string emitted by the signer (never re-serialised on verify).
            $table->text('breakdown_json');

            // sha256(breakdown_json) — secret-free, survives APP_KEY rotation.
            $table->char('breakdown_hash', 64);

            // hmac_sha256(breakdown_json, APP_KEY).
            $table->char('signature', 64);

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->foreignId('consumed_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            // Mobile clients pass X-Idempotency-Key; we persist it here so a
            // retried sale resolves the same quote without re-quoting.
            $table->string('idempotency_key', 80)->nullable();

            $table->timestamps();

            // Owner-side history queries: "show me quotes for this customer".
            $table->index(['shop_id', 'customer_id', 'created_at'], 'pos_quotes_shop_customer_created_idx');

            // Cleanup / expiry sweeps: "show me unconsumed quotes per shop".
            $table->index(['shop_id', 'consumed_at'], 'pos_quotes_shop_consumed_idx');

            // PostgreSQL treats NULLs as distinct in a UNIQUE constraint by
            // default, so multiple rows with NULL idempotency_key are allowed
            // while non-null values stay unique per shop.
            $table->unique(['shop_id', 'idempotency_key'], 'pos_quotes_shop_idem_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_quotes');
    }
};
