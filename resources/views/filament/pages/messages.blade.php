<x-filament-panels::page>
    <div style="display: flex; gap: 1rem; height: 600px;">

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
                    <div style="font-weight: 500;">{{ $conv['name'] }}</div>
                    <div style="font-size: 12px; opacity: 0.6; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        {{ $conv['last_message'] }}
                    </div>
                    @if($conv['unread_count'] > 0)
                        <span style="background: #f97316; color: white; border-radius: 999px; font-size: 11px; padding: 1px 7px;">
                            {{ $conv['unread_count'] }}
                        </span>
                    @endif
                </div>
            @empty
                <div style="padding: 1rem; opacity: 0.5;">No conversations yet.</div>
            @endforelse
        </div>

        {{-- Message thread --}}
        <div style="flex: 1; display: flex; flex-direction: column; border: 1px solid rgba(255,255,255,0.1); border-radius: 8px;">
            @if($selectedUserId)
                {{-- Messages --}}
                <div style="flex: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; gap: 0.5rem;">
                    @foreach($thread as $message)
                        <div style="display: flex; {{ $message->sender_id === auth()->id() ? 'justify-content: flex-end;' : 'justify-content: flex-start;' }}">
                            <div style="max-width: 70%; padding: 0.5rem 0.75rem; border-radius: 8px; {{ $message->sender_id === auth()->id() ? 'background: #f97316; color: white;' : 'background: rgba(255,255,255,0.1);' }}">
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
</x-filament-panels::page>