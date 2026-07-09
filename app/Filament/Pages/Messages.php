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

    /** All admin-side user ids — the inbox is shared across every admin. */
    public array $adminIds = [];

    public static function getNavigationBadge(): ?string
    {
        $adminIds = User::whereIn('role', ['admin', 'super_admin'])->pluck('id');

        $unread = Message::whereIn('receiver_id', $adminIds)
            ->whereNotIn('sender_id', $adminIds)
            ->where('is_read', false)
            ->count();

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function mount(): void
    {
        $this->adminIds     = User::whereIn('role', ['admin', 'super_admin'])->pluck('id')->all();
        $this->conversations = collect();
        $this->thread        = collect();
        $this->loadConversations();
    }

    public function loadConversations(): void
    {
        $adminIds = $this->adminIds;

        $this->conversations = Message::where(function ($q) use ($adminIds) {
                $q->whereIn('sender_id', $adminIds)
                  ->orWhereIn('receiver_id', $adminIds);
            })
            ->with(['sender', 'receiver'])
            ->orderBy('created_at', 'desc')
            ->get()
            // Keep only customer ↔ store traffic (skip admin-to-admin notes)
            ->reject(fn ($m) => in_array($m->sender_id, $adminIds) && in_array($m->receiver_id, $adminIds))
            ->groupBy(fn ($m) => in_array($m->sender_id, $adminIds) ? $m->receiver_id : $m->sender_id)
            ->map(function ($messages, $customerId) use ($adminIds) {
                $latest   = $messages->first();
                $customer = in_array($latest->sender_id, $adminIds)
                    ? $latest->receiver
                    : $latest->sender;

                $name = $customer
                    ? trim($customer->first_name . ' ' . $customer->last_name)
                    : '';

                return [
                    'user_id'         => (int) $customerId,
                    'name'            => $name !== '' ? $name : ($customer->email ?? "Customer #{$customerId}"),
                    'last_message'    => $latest->content,
                    'last_message_at' => $latest->created_at,
                    'unread_count'    => $messages->whereIn('receiver_id', $adminIds)
                                                  ->where('is_read', false)
                                                  ->count(),
                ];
            })
            ->values();
    }

    public function loadThread(): void
    {
        if (! $this->selectedUserId) {
            return;
        }

        $adminIds = $this->adminIds;
        $userId   = $this->selectedUserId;

        $this->thread = Message::where(function ($q) use ($adminIds, $userId) {
                $q->where('sender_id', $userId)->whereIn('receiver_id', $adminIds);
            })
            ->orWhere(function ($q) use ($adminIds, $userId) {
                $q->whereIn('sender_id', $adminIds)->where('receiver_id', $userId);
            })
            ->with('sender')
            ->orderBy('created_at', 'asc')
            ->get();

        // The thread is on screen — mark the customer's messages as read
        Message::where('sender_id', $userId)
            ->whereIn('receiver_id', $adminIds)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }

    public function selectUser(int $userId): void
    {
        $this->selectedUserId = $userId;
        $this->loadThread();
        $this->loadConversations();
    }

    /** Called by wire:poll — refreshes the inbox without a page reload. */
    public function pollMessages(): void
    {
        $this->loadConversations();
        $this->loadThread();
    }

    public function sendMessage(): void
    {
        if (! $this->selectedUserId || empty(trim($this->newMessage))) {
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
        $this->loadThread();
        $this->loadConversations();
    }
}
