<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending uploads for /api/mobile/v1/uploads/ presigned-intent flow (M6).
 *
 * Replaces the inline `image_base64` upload pattern used by the legacy mobile
 * endpoints. The flow:
 *
 *   1. Client POSTs intent (kind + content_type + size) → server mints a row
 *      with status=pending and a 15-minute TTL.
 *   2. Client PUTs raw bytes → server streams to disk, generates thumbnail,
 *      marks status=ready.
 *   3. When the client creates an Item (or Repair), it passes
 *      image_upload_id; the create flow calls UploadIntentService::consume()
 *      to link the upload to the new entity.
 *
 * Unconsumed/failed rows are reaped by `mobile:prune-uploads`.
 *
 * @see app/Http/Controllers/Api/Mobile/V1/UploadController.php
 * @see app/Services/Mobile/UploadIntentService.php
 * @see app/Console/Commands/PruneExpiredUploads.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pending_uploads')) {
            return;
        }

        Schema::create('pending_uploads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('kind', 40);
            $table->string('content_type', 60);
            $table->integer('declared_size_bytes');
            $table->integer('actual_size_bytes')->nullable();
            $table->string('upload_token', 80)->unique();
            $table->string('original_path', 255)->nullable();
            $table->string('thumbnail_path', 255)->nullable();
            $table->string('status', 20);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('consumed_by_type', 60)->nullable();
            $table->unsignedBigInteger('consumed_by_id')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            // Cleanup path: PruneExpiredUploads scans by (shop_id, status, expires_at).
            $table->index(['shop_id', 'status', 'expires_at'], 'pending_uploads_cleanup_index');

            // Forward reference: "which upload became this Item's image?"
            $table->index(['consumed_by_type', 'consumed_by_id'], 'pending_uploads_consumer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_uploads');
    }
};
