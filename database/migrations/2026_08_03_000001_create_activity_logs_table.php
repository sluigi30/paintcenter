<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // The admin / super admin who performed the action.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // What happened: created | updated | deleted | inventory | login
            $table->string('event');

            // The record acted on (polymorphic, but kept as plain columns
            // so the log survives even after the subject is deleted).
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();

            // Human sentence for custom entries (inventory moves, logins).
            $table->text('description')->nullable();

            // Structured before/after diff and extra metadata.
            $table->json('properties')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('user_id');
            $table->index('event');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
