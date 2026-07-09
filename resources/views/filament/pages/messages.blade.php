<x-filament-panels::page>
    <div id="msg-root" wire:poll.5s="pollMessages" style="display: flex; gap: 1rem; height: 600px;">

        {{-- Conversations list --}}
        <div style="width: 280px; border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; overflow-y: auto;">
            <div style="padding: 1rem; font-weight: 600; border-bottom: 1px solid rgba(255,255,255,0.1);">
                Conversations
            </div>
            @forelse($conversations as $conv)
                <div
                    wire:click="selectUser({{ $conv['user_id'] }})"
                    style="padding: 0.75rem 1rem; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,0.05); {{ $selectedUserId === $conv['user_id'] ? 'background: rgba(255,255,255,0.05);' : '' }}"
                >
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                        <span style="font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $conv['name'] }}</span>
                        @if($conv['unread_count'] > 0)
                            <span style="background: #f97316; color: white; border-radius: 999px; font-size: 11px; padding: 1px 7px; flex-shrink: 0;">
                                {{ $conv['unread_count'] }}
                            </span>
                        @endif
                    </div>
                    <div style="font-size: 12px; opacity: 0.6; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        {{ $conv['last_message'] }}
                    </div>
                    <div style="font-size: 10px; opacity: 0.4; margin-top: 2px;">
                        {{ $conv['last_message_at']->diffForHumans() }}
                    </div>
                </div>
            @empty
                <div style="padding: 1rem; opacity: 0.5;">No conversations yet.</div>
            @endforelse
        </div>

        {{-- Message thread --}}
        <div style="flex: 1; display: flex; flex-direction: column; border: 1px solid rgba(255,255,255,0.1); border-radius: 8px;">
            @if($selectedUserId)
                {{-- Messages --}}
                <div id="msg-thread" data-user="{{ $selectedUserId }}" style="flex: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; gap: 0.5rem;">
                    @foreach($thread as $message)
                        @php $isStoreSide = in_array($message->sender_id, $adminIds); @endphp
                        <div style="display: flex; {{ $isStoreSide ? 'justify-content: flex-end;' : 'justify-content: flex-start;' }}">
                            <div style="max-width: 70%; padding: 0.5rem 0.75rem; border-radius: 8px; {{ $isStoreSide ? 'background: #f97316; color: white;' : 'background: rgba(255,255,255,0.1);' }}">
                                @if($isStoreSide && $message->sender_id !== auth()->id())
                                    <div style="font-size: 10px; font-weight: 700; opacity: 0.85; margin-bottom: 2px;">
                                        {{ $message->sender?->first_name ?? 'Admin' }}
                                    </div>
                                @endif
                                {{ $message->content }}
                                <div style="font-size: 10px; opacity: 0.7; margin-top: 2px;">
                                    {{ $message->created_at->format('M d, h:i A') }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Reply box --}}
                <div style="padding: 1rem; border-top: 1px solid rgba(255,255,255,0.1); display: flex; gap: 0.5rem;">
                    <input
                        wire:model="newMessage"
                        wire:keydown.enter="sendMessage"
                        type="text"
                        placeholder="Type a message..."
                        style="flex: 1; padding: 0.5rem 0.75rem; border-radius: 6px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: inherit; outline: none;"
                    />
                    <button
                        wire:click="sendMessage"
                        style="padding: 0.5rem 1rem; background: #f97316; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;"
                    >
                        Send
                    </button>
                </div>
            @else
                <div style="flex: 1; display: flex; align-items: center; justify-content: center; opacity: 0.4;">
                    Select a conversation to start messaging
                </div>
            @endif
        </div>
    </div>

    <script>
    (function () {
        let lastUser = null;

        // Keep the thread pinned to the newest message: always on thread
        // switch, and on new messages unless the admin scrolled up to read
        // older history.
        function scrollThread(force) {
            const el = document.getElementById('msg-thread');
            if (!el) return;
            const uid        = el.dataset.user;
            const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 150;
            if (force || uid !== lastUser || nearBottom) {
                el.scrollTop = el.scrollHeight;
            }
            lastUser = uid;
        }

        const root = document.getElementById('msg-root');
        if (root) {
            new MutationObserver(() => scrollThread(false))
                .observe(root, { childList: true, subtree: true });
        }
        scrollThread(true);
    })();
    </script>
</x-filament-panels::page>
