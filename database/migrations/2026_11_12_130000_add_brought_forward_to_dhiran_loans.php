<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A loan made on paper before the shop used Dhiran, entered as it stood on the day it was brought in (owner 2026-11-12). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dhiran_loans', function (Blueprint $table) {
            $table->boolean('brought_forward')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('dhiran_loans', function (Blueprint $table) {
            $table->dropColumn('brought_forward');
        });
    }
};
