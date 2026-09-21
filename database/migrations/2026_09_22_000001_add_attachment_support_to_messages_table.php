<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes a message able to carry photos instead of words, and to say what kind
 * of message it is.
 *
 * `content` becomes NULLABLE — a customer photographing the wrong shade has
 * said everything that needs saying, and forcing them to type a caption first
 * is a caption nobody reads. The cost is that every surface printing a message
 * as a one-line preview (the API conversation list, the admin inbox sidebar,
 * the mobile bubble) now has a row that renders blank; they all go through
 * `Message::preview()` instead of touching `content` directly.
 *
 * `kind` separates what the store TYPED from what the system POSTED. Order
 * updates come from OrderMessageService through an admin account, so today
 * they are indistinguishable from an admin sitting there writing them one by
 * one. Defaulted to 'chat' so every existing row keeps its current meaning and
 * appearance — history is deliberately NOT backfilled. Recognising an old
 * order update means pattern-matching its wording, and an admin who once typed
 * something similar would be miscategorised with no way to tell.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->text('content')->nullable()->change();

            // NOT NULL DEFAULT, like base_code/color_code on variants: the
            // column is never absent, only ever 'chat' until something says
            // otherwise.
            $table->string('kind', 20)->default('chat')->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('kind');
            // Not reverted to NOT NULL: rows written while this was up may
            // legitimately hold NULL, and the down() would fail on them.
        });
    }
};
