<?php

namespace App\Filament\Pages;

use App\Models\Message;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class Messages extends Page
{
    protected string $view = 'filament.pages.messages';
    protected static ?string $title = 'Messages';
    protected static ?string $navigationLabel = 'Messages';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    public ?int $selectedUserId = null;
    public string $newMessage = '';
    public Collection $conversations;
    public Collection $thread;

    public function mount(): void
    {
        $this->conversations = collect();
        $this->thread        = collect();
        $this->loadConversations();
    }

    public function loadConversations(): void
    {
        $adminId = auth()->id();

        $this->conversations = Message::where('sender_id', $adminId)
            ->orWhere('receiver_id', $adminId)
            ->with(['sender', 'receiver'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy(function ($message) use ($adminId) {
                return $message->sender_id === $adminId
                    ? $message->receiver_id
                    : $message->sender_id;
            })
            ->map(function ($messages, $otherUserId) use ($adminId) {
                $latest    = $messages->first();
                $otherUser = $latest->sender_id === $adminId
                    ? $latest->receiver
                    : $latest->sender;

                return [
                    'user_id'         => $otherUser->id,
                    'name'            => $otherUser->first_name . ' ' . $otherUser->last_name,
                    'last_message'    => $latest->content,
                    'last_message_at' => $latest->created_at,
                    'unread_count'    => $messages->where('receiver_id', $adminId)
                                                  ->where('is_read', false)
                                                  ->count(),
                ];
            })
            ->values();
    }

    public function selectUser(int $userId): void
    {
        $this->selectedUserId = $userId;
        $adminId              = auth()->id();

        $this->thread = Message::where(function ($q) use ($adminId, $userId) {
                $q->where('sender_id', $adminId)->where('receiver_id', $userId);
            })
            ->orWhere(function ($q) use ($adminId, $userId) {
                $q->where('sender_id', $userId)->where('receiver_id', $adminId);
            })
            ->orderBy('created_at', 'asc')
            ->get();

        // Mark as read
        Message::where('sender_id', $userId)
            ->where('receiver_id', $adminId)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $this->loadConversations();
    }

    public function sendMessage(): void
    {
        if (!$this->selectedUserId || empty(trim($this->newMessage))) {
            return;
        }

        Message::create([
            'sender_id'   => auth()->id(),
            'receiver_id' => $this->selectedUserId,
            'content'     => trim($this->newMessage),
            'timestamp'   => now(),
            'is_read'     => false,
        ]);

        $this->newMessage = '';
        $this->selectUser($this->selectedUserId);
    }
}