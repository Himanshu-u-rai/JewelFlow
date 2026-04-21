<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create roles table
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g., 'owner', 'manager', 'staff'
            $table->string('display_name'); // e.g., 'Shop Owner', 'Manager', 'Staff'
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Create permissions table
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g., 'items.create', 'items.delete'
            $table->string('display_name'); // e.g., 'Create Items'
            $table->string('group')->nullable(); // e.g., 'inventory', 'sales', 'reports'
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Create role_permission pivot table
        Schema::create('role_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->foreignId('permission_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });

        // Add role_id to users table and remove role column
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('shop_id')->constrained()->onDelete('set null');
        });

        // Remove old role column if it exists
        if (Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
            $table->string('role')->nullable();
        });

        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
