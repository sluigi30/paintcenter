<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manufacturer color identity as plain fields ("888" / "Red").
 * Deliberately simple — a normalized per-brand color library was
 * built and reverted on 2026-07-12 as too complicated for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('color_code', 40)->nullable()->after('description');
            $table->string('color_name', 100)->nullable()->after('color_code');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['color_code', 'color_name']);
        });
    }
};
