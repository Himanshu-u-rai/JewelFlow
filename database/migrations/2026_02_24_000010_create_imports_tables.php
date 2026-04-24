<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->string('type'); // catalog | manufacture
            $table->string('status')->default('preview'); // queued | preview | running | completed | failed
            $table->string('mode')->nullable(); // strict | row
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('file_path');
            $table->string('error_file_path')->nullable();
            $table->json('preview_summary')->nullable();
            $table->json('execution_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'type', 'status']);
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('status')->default('valid'); // valid | invalid | imported | failed
            $table->text('error_message')->nullable();
            $table->json('payload');
            $table->json('computed')->nullable();
            $table->timestamps();

            $table->index(['import_id', 'status']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('imports');
    }
};

