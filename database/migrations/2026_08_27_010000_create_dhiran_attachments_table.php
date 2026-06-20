<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private, shop-scoped attachments for Dhiran evidence (Phase E2).
 *
 * One polymorphic table for every Dhiran file need — pledged-item photos, borrower
 * ID-proof images, and loan documents — instead of three separate tables. Files
 * live on the PRIVATE disk; the path is never publicly served. Every row carries
 * shop_id and is always filtered by tenant. shop_id → shops ON DELETE RESTRICT so
 * a shop holding evidence cannot be hard-deleted (matches the financial tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dhiran_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            // Polymorphic owner: dhiran_loan | dhiran_loan_item | customer.
            $table->string('owner_type', 40);
            $table->unsignedBigInteger('owner_id');
            // e.g. item_photo, id_proof_front, id_proof_back, address_proof,
            // borrower_photo, pledge_agreement, signed_terms, valuation_proof, loan_document.
            $table->string('document_type', 40);
            $table->string('file_disk', 20)->default('local'); // 'local' = storage/app/private
            $table->string('file_path', 1024);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'owner_type', 'owner_id']);
            $table->index(['shop_id', 'document_type']);

            $table->foreign('shop_id')->references('id')->on('shops')->restrictOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dhiran_attachments');
    }
};
