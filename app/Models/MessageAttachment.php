<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * One file attached to a message.
 *
 * Attachments are NEVER addressed by a public storage URL. They are customers'
 * photographs — a delivery that arrived in the wrong shade, a receipt, a wall
 * with the house number in frame — and a guessable /storage/… path makes every
 * one of them readable by anyone who can count. Everything goes through
 * MessageAttachmentController, which checks the thread membership first.
 */
class MessageAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'width',
        'height',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'size'       => 'integer',
            'width'      => 'integer',
            'height'     => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * `url` is the app's own authenticated endpoint, never the disk's path.
     * `path` and `disk` are hidden for the same reason — a client has no use
     * for them, and printing the storage layout into every JSON response is
     * how it ends up being requested directly.
     */
    protected $appends = ['url'];

    protected $hidden = ['path', 'disk'];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function getUrlAttribute(): string
    {
        return route('api.messages.attachments.show', $this);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /** The disk this row was actually written to — not today's default. */
    public function storage()
    {
        return Storage::disk($this->disk);
    }

    public function exists(): bool
    {
        return $this->storage()->exists($this->path);
    }

    /**
     * Delete the file, then the row. Deliberately tolerant: a file already
     * gone is the outcome we wanted, and throwing here would strand the row
     * that points at it.
     */
    public function deleteWithFile(): void
    {
        try {
            $this->storage()->delete($this->path);
        } catch (\Throwable $e) {
            report($e);
        }

        $this->delete();
    }
}
