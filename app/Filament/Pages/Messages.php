<?php

namespace App\Filament\Pages;

use App\Models\Message;
use App\Models\User;
use App\Services\MessageAttachmentService;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\WithFileUploads;

class Messages extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.pages.messages';

    protected static ?string $title = 'Messages';

    protected static ?string $navigationLabel = 'Messages';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    public ?int $selectedUserId = null;

    public ?string $selectedUserName = null;

    public string $newMessage = '';

    public Collection $conversations;

    public Collection $thread;

    /** Photos staged for the next reply. Livewire temporary uploads. */
    public array $photos = [];

    /** Compose mode: pick any customer and start a thread from scratch. */
    public bool $composing = false;

    public string $customerSearch = '';

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
        $this->adminIds = User::whereIn('role', ['admin', 'super_admin'])->pluck('id')->all();
        $this->conversations = collect();
        $this->thread = collect();
        $this->loadConversations();
    }

    /**
     * The inbox, in three queries.
     *
     * This runs on a five-second poll, for every admin with the page open, so
     * its shape matters more than it looks. It used to read EVERY message the
     * store had ever exchanged into PHP — both user relations eager-loaded —
     * and group the collection in memory, which cost grew with the history
     * rather than with the number of customers in it. Attachments would have
     * been loaded along with it.
     */
    public function loadConversations(): void
    {
        $adminIds = $this->adminIds;

        if ($adminIds === []) {
            $this->conversations = collect();

            return;
        }

        // Cast and inlined rather than bound: the CASE expression has to appear
        // identically in SELECT and GROUP BY, and these are ids straight out of
        // the users table.
        $ids = implode(',', array_map('intval', $adminIds));
        $counterpart = "CASE WHEN sender_id IN ({$ids}) THEN receiver_id ELSE sender_id END";

        $summaries = Message::query()
            ->selectRaw("{$counterpart} as customer_id")
            ->selectRaw('MAX(id) as last_id')
            ->selectRaw("SUM(CASE WHEN receiver_id IN ({$ids}) AND is_read = 0 THEN 1 ELSE 0 END) as unread_count")
            ->whereRaw("(sender_id IN ({$ids}) OR receiver_id IN ({$ids}))")
            // Keep only customer ↔ store traffic (skip admin-to-admin notes)
            ->whereRaw("NOT (sender_id IN ({$ids}) AND receiver_id IN ({$ids}))")
            ->groupBy(DB::raw($counterpart))
            ->get();

        // The preview line falls back to the attachments when a message is a
        // photo and nothing else, so they load here — as ONE query for the
        // whole inbox, not one per row.
        $latest = Message::with('attachments')
            ->whereIn('id', $summaries->pluck('last_id'))
            ->get()
            ->keyBy('id');

        $customers = User::whereIn('id', $summaries->pluck('customer_id'))
            ->get()
            ->keyBy('id');

        $this->conversations = $summaries
            ->map(function ($row) use ($latest, $customers) {
                $message = $latest->get($row->last_id);
                $customer = $customers->get($row->customer_id);

                if (! $message) {
                    return null;
                }

                $name = $customer
                    ? trim($customer->first_name.' '.$customer->last_name)
                    : '';

                return [
                    'user_id'         => (int) $row->customer_id,
                    'name'            => $name !== '' ? $name : ($customer->email ?? "Customer #{$row->customer_id}"),
                    'last_message'    => $message->preview(),
                    'last_message_at' => $message->created_at,
                    'unread_count'    => (int) $row->unread_count,
                ];
            })
            ->filter()
            ->sortByDesc('last_message_at')
            ->values();
    }

    public function loadThread(): void
    {
        if (! $this->selectedUserId) {
            return;
        }

        $adminIds = $this->adminIds;
        $userId = $this->selectedUserId;

        $this->thread = Message::where(function ($q) use ($adminIds, $userId) {
            $q->where('sender_id', $userId)->whereIn('receiver_id', $adminIds);
        })
            ->orWhere(function ($q) use ($adminIds, $userId) {
                $q->whereIn('sender_id', $adminIds)->where('receiver_id', $userId);
            })
            // One query for every photo in the thread. Lazily loading them
            // here is one query per bubble, on a five-second poll.
            ->with(['sender', 'attachments'])
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
        $this->selectedUserName = $this->customerName($userId);
        $this->photos = [];
        $this->loadThread();
        $this->loadConversations();
    }

    /**
     * Customers the admin can start a thread with. The inbox itself is built
     * from existing messages, so without this an admin can only ever reply —
     * a customer who has never written in is unreachable.
     */
    public function customerList(): Collection
    {
        $term = trim($this->customerSearch);

        return User::query()
            ->customers()
            ->where('is_archived', false)
            ->when($term !== '', function ($query) use ($term) {
                $like = '%'.$term.'%';
                $query->where(fn ($q) => $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like));
            })
            ->orderBy('first_name')
            ->limit(50)
            ->get()
            ->map(fn ($user) => [
                'user_id' => $user->id,
                'name'    => trim($user->first_name.' '.$user->last_name) ?: $user->email,
                'email'   => $user->email,
            ]);
    }

    public function toggleCompose(): void
    {
        $this->composing = ! $this->composing;
        $this->customerSearch = '';
    }

    public function startConversation(int $userId): void
    {
        $this->composing = false;
        $this->customerSearch = '';
        $this->selectUser($userId);
    }

    protected function customerName(int $userId): ?string
    {
        $user = User::find($userId);

        if (! $user) {
            return null;
        }

        return trim($user->first_name.' '.$user->last_name) ?: $user->email;
    }

    /** Called by wire:poll — refreshes the inbox without a page reload. */
    public function pollMessages(): void
    {
        $this->loadConversations();
        $this->loadThread();
    }

    /**
     * Validate each photo the moment it lands, not at send time. A rejected
     * file that sits in the tray until the admin presses Send is a file they
     * have already stopped thinking about.
     *
     * A refused file is also REMOVED, not just flagged. It can never be sent,
     * and Livewire throws outright when asked for a preview URL for something
     * it cannot preview - so leaving it staged is an error message pinned to a
     * thumbnail that takes the whole page down with it.
     */
    public function updatedPhotos(): void
    {
        $validator = Validator::make(
            ['photos' => $this->photos],
            [
                'photos'   => ['array', 'max:'.MessageAttachmentService::MAX_FILES],
                'photos.*' => [
                    'image',
                    'mimes:'.implode(',', MessageAttachmentService::ACCEPTED_EXTENSIONS),
                    'max:'.MessageAttachmentService::MAX_FILE_KB,
                ],
            ],
            MessageAttachmentService::validationMessages(),
        );

        $this->resetErrorBag('photos');

        if ($validator->passes()) {
            return;
        }

        $this->photos = array_values(array_filter(
            $this->photos,
            fn ($photo, $index) => ! $validator->errors()->has("photos.{$index}"),
            ARRAY_FILTER_USE_BOTH,
        ));

        // Reported against `photos` rather than per index: the offending
        // entries are gone, so an index-keyed message would point at whatever
        // moved up into its place.
        foreach (array_unique($validator->errors()->all()) as $error) {
            $this->addError('photos', $error);
        }
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);

        $this->photos = array_values($this->photos);
    }

    public function sendMessage(): void
    {
        if (! $this->selectedUserId) {
            return;
        }

        $photos = array_values(array_filter($this->photos));

        if (trim($this->newMessage) === '' && $photos === []) {
            return;
        }

        // The same service the API sends through, so a photo pasted here and
        // one sent from the app are stored, measured and ordered alike.
        MessageAttachmentService::send(
            senderId: auth()->id(),
            receiverId: $this->selectedUserId,
            content: $this->newMessage,
            files: $photos,
        );

        $this->newMessage = '';
        $this->photos = [];
        $this->loadThread();
        $this->loadConversations();
    }
}
