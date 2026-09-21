<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files hanging off a message — photos, in practice.
 *
 * A table rather than a JSON column on `messages` (the way `products.images`
 * does it), because the two cases are not alike. Product images are a public
 * catalogue asset: an ordered list of paths is the whole truth about them.
 * A chat attachment is a customer's photograph, and it needs a mime type to
 * refuse anything unexpected, a byte size to show before downloading, pixel
 * dimensions to lay the bubble out, and a row identity so a single file can be
 * authorised, served and audited on its own.
 *
 * `disk` is stored PER ROW, not read from config at render time. The store is
 * on a local disk today and object storage later (see CLOUD_STORAGE.md); when
 * that flips, rows written before the flip must keep resolving against the
 * disk they were actually written to, or every historical photo 404s.
 *
 * `width`/`height` are captured at upload so the thread can reserve the right
 * aspect ratio before the image has loaded — without them every photo arrives
 * as a layout jump. Nullable because a file we cannot measure is still a file
 * we can serve.
 *
 * NOTE on deletion: this cascades from `messages`, which itself cascades from
 * `users`. A database-level cascade fires NO Eloquent events, so an observer
 * cleaning up files would silently not run and the files would outlive every
 * row pointing at them. `messages:prune-orphan-attachments` is what actually
 * removes them; the model events are only the fast path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();

            $table->string('disk', 40)->default('public');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');          // bytes
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // Presentation order within the message. Tie-broken on id at query
            // time — these are assigned per upload batch and can collide.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['message_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
