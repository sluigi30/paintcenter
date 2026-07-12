<x-filament-panels::page>
<style>
/* ════════════════════════════════════════════════════════════
   MESSAGES — theme-aware (light defaults, html.dark overrides)
════════════════════════════════════════════════════════════ */
#msg-root{
    --msg-panel:      #ffffff;
    --msg-panel-alt:  #f9fafb;
    --msg-border:     #e5e7eb;
    --msg-hover:      #f9fafb;
    --msg-active:     #fef2f2;
    --msg-text:       #111827;
    --msg-text-2:     #6b7280;
    --msg-text-3:     #9ca3af;
    --msg-bubble-in:  #f3f4f6;
    --msg-input-bg:   #ffffff;
    --msg-brand:      #b91c1c;
    --msg-brand-hi:   #dc2626;
    --msg-brand-dark: #991b1b;
}
html.dark #msg-root{
    --msg-panel:      rgba(255,255,255,.03);
    --msg-panel-alt:  rgba(255,255,255,.04);
    --msg-border:     rgba(255,255,255,.12);
    --msg-hover:      rgba(255,255,255,.05);
    --msg-active:     rgba(185,28,28,.16);
    --msg-text:       #f4f4f5;
    --msg-text-2:     #a1a1aa;
    --msg-text-3:     #71717a;
    --msg-bubble-in:  rgba(255,255,255,.08);
    --msg-input-bg:   rgba(255,255,255,.05);
}

#msg-root{display:flex;gap:16px;height:calc(100vh - 220px);min-height:480px}

/* ── Sidebar ── */
.msg-side{width:300px;flex-shrink:0;display:flex;flex-direction:column;overflow:hidden;
    background:var(--msg-panel);border:1px solid var(--msg-border);border-radius:14px;
    box-shadow:0 1px 2px rgba(0,0,0,.04)}
.msg-side-head{display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:14px 16px;border-bottom:1px solid var(--msg-border)}
.msg-side-title{font-size:13px;font-weight:700;color:var(--msg-text);margin:0;
    text-transform:uppercase;letter-spacing:.06em}
.msg-side-count{font-size:11px;font-weight:700;color:var(--msg-brand);
    background:var(--msg-active);border-radius:999px;padding:2px 9px}
.msg-side-list{flex:1;overflow-y:auto}

.msg-conv{display:flex;gap:11px;align-items:flex-start;padding:12px 14px;cursor:pointer;
    border-bottom:1px solid var(--msg-border);border-left:3px solid transparent;
    transition:background .12s}
.msg-conv:hover{background:var(--msg-hover)}
.msg-conv.active{background:var(--msg-active);border-left-color:var(--msg-brand)}

.msg-avatar{width:38px;height:38px;border-radius:50%;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    font-size:14px;font-weight:700;color:#fff;letter-spacing:.02em;
    background:linear-gradient(135deg,var(--msg-brand-hi),var(--msg-brand-dark))}
.msg-conv-body{flex:1;min-width:0}
.msg-conv-top{display:flex;align-items:baseline;justify-content:space-between;gap:8px}
.msg-conv-name{font-size:13.5px;font-weight:600;color:var(--msg-text);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.msg-conv-time{font-size:10.5px;color:var(--msg-text-3);white-space:nowrap;flex-shrink:0}
.msg-conv-bottom{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:2px}
.msg-conv-preview{font-size:12px;color:var(--msg-text-2);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.msg-unread{background:var(--msg-brand);color:#fff;border-radius:999px;flex-shrink:0;
    font-size:10.5px;font-weight:700;padding:1px 7px;line-height:1.5}

.msg-empty-side{padding:24px 16px;font-size:13px;color:var(--msg-text-3);text-align:center}

/* ── Thread panel ── */
.msg-main{flex:1;display:flex;flex-direction:column;min-width:0;overflow:hidden;
    background:var(--msg-panel);border:1px solid var(--msg-border);border-radius:14px;
    box-shadow:0 1px 2px rgba(0,0,0,.04)}
.msg-main-head{display:flex;align-items:center;gap:11px;padding:12px 16px;
    border-bottom:1px solid var(--msg-border);background:var(--msg-panel-alt)}
.msg-main-name{font-size:14px;font-weight:700;color:var(--msg-text);margin:0}
.msg-main-sub{font-size:11px;color:var(--msg-text-3);margin:1px 0 0}

#msg-thread{flex:1;overflow-y:auto;padding:18px 16px;display:flex;flex-direction:column;gap:10px}

.msg-row{display:flex}
.msg-row.out{justify-content:flex-end}
.msg-row.in{justify-content:flex-start}
.msg-bubble{max-width:70%;padding:8px 13px;font-size:13.5px;line-height:1.45;
    border-radius:16px;word-break:break-word}
.msg-row.out .msg-bubble{color:#fff;border-bottom-right-radius:5px;
    background:linear-gradient(135deg,var(--msg-brand-hi),var(--msg-brand));
    box-shadow:0 1px 3px rgba(185,28,28,.25)}
.msg-row.in .msg-bubble{background:var(--msg-bubble-in);color:var(--msg-text);
    border-bottom-left-radius:5px}
.msg-sender{font-size:10px;font-weight:700;opacity:.85;margin-bottom:2px}
.msg-time{font-size:10px;margin-top:3px;text-align:right}
.msg-row.out .msg-time{color:rgba(255,255,255,.75)}
.msg-row.in .msg-time{color:var(--msg-text-3)}

/* ── Composer ── */
.msg-composer{display:flex;gap:8px;padding:12px 14px;border-top:1px solid var(--msg-border);
    background:var(--msg-panel-alt)}
.msg-input{flex:1;padding:9px 14px;border-radius:10px;font-size:13.5px;font-family:inherit;
    background:var(--msg-input-bg);border:1px solid var(--msg-border);
    color:var(--msg-text);outline:none;transition:border-color .15s,box-shadow .15s}
.msg-input::placeholder{color:var(--msg-text-3)}
.msg-input:focus{border-color:var(--msg-brand);box-shadow:0 0 0 3px rgba(185,28,28,.12)}
.msg-send{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border:none;
    border-radius:10px;font-size:13px;font-weight:700;color:#fff;cursor:pointer;
    background:linear-gradient(180deg,var(--msg-brand-hi),var(--msg-brand));
    box-shadow:0 1px 3px rgba(185,28,28,.35);transition:all .15s;white-space:nowrap}
.msg-send:hover{background:linear-gradient(180deg,var(--msg-brand),var(--msg-brand-dark));
    box-shadow:0 3px 8px rgba(185,28,28,.4);transform:translateY(-1px)}
.msg-send:active{transform:translateY(0)}

/* ── Empty thread state ── */
.msg-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
    gap:10px;color:var(--msg-text-3)}
.msg-empty svg{opacity:.45}
.msg-empty p{font-size:13.5px;margin:0}

@media(max-width:768px){
    #msg-root{flex-direction:column;height:auto}
    .msg-side{width:100%;max-height:280px}
    .msg-main{min-height:420px}
}
</style>

@php
    $initials = function (string $name): string {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
        return mb_strtoupper($first . $last) ?: '?';
    };
    $selectedConv = collect($conversations)->firstWhere('user_id', $selectedUserId);
    $totalUnread  = collect($conversations)->sum('unread_count');
@endphp

<div id="msg-root" wire:poll.5s="pollMessages">

    {{-- Conversations list --}}
    <div class="msg-side">
        <div class="msg-side-head">
            <p class="msg-side-title">Conversations</p>
            @if($totalUnread > 0)
                <span class="msg-side-count">{{ $totalUnread }} new</span>
            @endif
        </div>
        <div class="msg-side-list">
            @forelse($conversations as $conv)
                <div
                    wire:click="selectUser({{ $conv['user_id'] }})"
                    class="msg-conv {{ $selectedUserId === $conv['user_id'] ? 'active' : '' }}"
                >
                    <div class="msg-avatar">{{ $initials($conv['name']) }}</div>
                    <div class="msg-conv-body">
                        <div class="msg-conv-top">
                            <span class="msg-conv-name">{{ $conv['name'] }}</span>
                            <span class="msg-conv-time">{{ $conv['last_message_at']->shortAbsoluteDiffForHumans() }}</span>
                        </div>
                        <div class="msg-conv-bottom">
                            <span class="msg-conv-preview">{{ $conv['last_message'] }}</span>
                            @if($conv['unread_count'] > 0)
                                <span class="msg-unread">{{ $conv['unread_count'] }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="msg-empty-side">No conversations yet.</div>
            @endforelse
        </div>
    </div>

    {{-- Message thread --}}
    <div class="msg-main">
        @if($selectedUserId)
            <div class="msg-main-head">
                <div class="msg-avatar" style="width:34px;height:34px;font-size:12.5px">{{ $initials($selectedConv['name'] ?? '?') }}</div>
                <div>
                    <p class="msg-main-name">{{ $selectedConv['name'] ?? 'Conversation' }}</p>
                    <p class="msg-main-sub">Customer conversation</p>
                </div>
            </div>

            {{-- Messages --}}
            <div id="msg-thread" data-user="{{ $selectedUserId }}">
                @foreach($thread as $message)
                    @php $isStoreSide = in_array($message->sender_id, $adminIds); @endphp
                    <div class="msg-row {{ $isStoreSide ? 'out' : 'in' }}">
                        <div class="msg-bubble">
                            @if($isStoreSide && $message->sender_id !== auth()->id())
                                <div class="msg-sender">{{ $message->sender?->first_name ?? 'Admin' }}</div>
                            @endif
                            {{ $message->content }}
                            <div class="msg-time">{{ $message->created_at->format('M d, h:i A') }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Reply box --}}
            <div class="msg-composer">
                <input
                    wire:model="newMessage"
                    wire:keydown.enter="sendMessage"
                    type="text"
                    placeholder="Type a message..."
                    class="msg-input"
                />
                <button wire:click="sendMessage" class="msg-send">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                    Send
                </button>
            </div>
        @else
            <div class="msg-empty">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                <p>Select a conversation to start messaging</p>
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
