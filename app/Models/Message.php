<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    /** Typed by a person — a customer, or an admin replying. */
    public const KIND_CHAT = 'chat';

    /** Posted by OrderMessageService. Rendered as a system card, not a bubble. */
    public const KIND_ORDER_UPDATE = 'order_update';

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'content',
        'kind',
        'timestamp',
        'is_read',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Fast path only. A message deleted through Eloquent takes its files
        // with it — but `message_attachments` also cascades at the DATABASE
        // level from `messages`, and that cascade fires no model events at
        // all. Deleting a user therefore removes every row and leaves every
        // file behind, silently. `messages:prune-orphan-attachments` is the
        // mechanism that actually guarantees cleanup; this is the courtesy.
        static::deleting(function (Message $message) {
            $message->attachments->each->deleteWithFile();
        });
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    /**
     * Ordered explicitly, and that is not optional — the same lesson as
     * Product::variants(). An unordered read gets answered from whichever
     * index the planner likes and comes back in an order nobody chose, so the
     * photos in a bubble shuffle between clients. Tie-broken on id because
     * sort_order is assigned per upload batch and values do collide.
     */
    public function attachments()
    {
        return $this->hasMany(MessageAttachment::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function isOrderUpdate(): bool
    {
        return $this->kind === self::KIND_ORDER_UPDATE;
    }

    /**
     * The one-line form, for anywhere a message is listed rather than read:
     * the API conversation list, the admin inbox sidebar.
     *
     * Deliberately a METHOD, not an appended attribute. `content` is nullable
     * now, so every listing surface needs this — but the thread endpoints
     * render the real content and the real attachments and have no use for it,
     * and a global $appends would make every message everywhere pay for it.
     *
     * Callers must have `attachments` loaded. A conversation list is one
     * message per conversation, so eager-loading it is a single extra query;
     * getting that wrong is an N+1 across the whole inbox.
     */
    public function preview(): string
    {
        $text = trim((string) $this->content);

        if ($text !== '') {
            return $text;
        }

        $count = $this->attachments->count();

        return match (true) {
            $count === 0 => '',
            $count === 1 => '📎 Photo',
            default      => "📎 {$count} photos",
        };
    }

    /**
     * Who may read this message and open its files.
     *
     * The store inbox is shared: a customer's thread is answered by whichever
     * admin is about, so ANY admin can see it — the same rule
     * MessageController::thread() already builds its query on. Keep the two
     * in step or an admin will see a thread they cannot open the photos in.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        return $this->sender_id === $user->id || $this->receiver_id === $user->id;
    }
}
