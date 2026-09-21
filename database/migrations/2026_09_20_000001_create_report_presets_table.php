<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved report configurations.
 *
 * The payload holds CONFIGURATION ONLY — which sections, how to group, what to
 * compare against, how many rows — and never a figure. Opening a preset always
 * re-runs the queries, so "Monthly Owner Report" opened in November reports
 * November, not whatever it happened to show the day it was saved.
 *
 * The date range inside it is normally RELATIVE for the same reason: a preset
 * that stored 2026-09-01 to 2026-09-30 would need its dates re-picked every
 * month, which is exactly the one click it exists to save.
 *
 * Presets are per-admin. Unique on (user_id, name) so saving under a name that
 * already exists updates that preset instead of quietly creating a second one
 * with the same label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('payload');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_presets');
    }
};
