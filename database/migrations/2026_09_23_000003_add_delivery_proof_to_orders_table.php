<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The photo a driver takes at handover.
     *
     * Columns on `orders` rather than a table: exactly one photo, taken once,
     * at the moment a delivery completes — the same reasoning that kept the
     * driver assignment on the order. A gallery is not wanted and a second
     * photo has no meaning here. Should failed-attempt photos ever be added,
     * THAT is when a table earns its place.
     *
     * `disk` is stored PER ROW, like message_attachments, so the eventual move
     * to object storage leaves every photo already written still resolvable
     * against the disk it was actually put on.
     *
     * `proof_captured_at` deliberately OUTLIVES the file. The 12-month prune
     * clears the path, mime and size but keeps this, so an old order still
     * records that a photo was taken and when — rather than reading as a
     * delivery that never had one.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('proof_disk')->nullable()->after('cash_collected_at');
            $table->string('proof_path')->nullable()->after('proof_disk');
            $table->string('proof_mime')->nullable()->after('proof_path');
            $table->unsignedInteger('proof_size')->nullable()->after('proof_mime');
            $table->timestamp('proof_captured_at')->nullable()->after('proof_size');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'proof_disk',
                'proof_path',
                'proof_mime',
                'proof_size',
                'proof_captured_at',
            ]);
        });
    }
};
