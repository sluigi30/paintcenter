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

/* ── Compose (start a new conversation) ── */
.msg-new-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border:none;
    border-radius:8px;font-size:11.5px;font-weight:700;color:#fff;cursor:pointer;
    background:linear-gradient(180deg,var(--msg-brand-hi),var(--msg-brand));
    box-shadow:0 1px 3px rgba(185,28,28,.3);transition:all .15s;white-space:nowrap}
.msg-new-btn:hover{background:linear-gradient(180deg,var(--msg-brand),var(--msg-brand-dark))}
.msg-new-btn.is-open{background:none;color:var(--msg-text-2);box-shadow:none;
    border:1px solid var(--msg-border)}
.msg-new-btn.is-open:hover{background:var(--msg-hover)}
.msg-search-wrap{padding:10px 12px;border-bottom:1px solid var(--msg-border);
    background:var(--msg-panel-alt)}
.msg-search{width:100%;padding:7px 11px;border-radius:8px;font-size:12.5px;font-family:inherit;
    background:var(--msg-input-bg);border:1px solid var(--msg-border);
    color:var(--msg-text);outline:none;transition:border-color .15s,box-shadow .15s}
.msg-search::placeholder{color:var(--msg-text-3)}
.msg-search:focus{border-color:var(--msg-brand);box-shadow:0 0 0 3px rgba(185,28,28,.12)}
.msg-cust-email{font-size:11px;color:var(--msg-text-3);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.msg-thread-hint{margin:auto;max-width:290px;text-align:center;font-size:12.5px;
    line-height:1.5;color:var(--msg-text-3)}

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

/* ── Day separator ── */
.msg-day{display:flex;align-items:center;justify-content:center;margin:6px 0 2px}
.msg-day span{font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
    color:var(--msg-text-3);background:var(--msg-panel-alt);border:1px solid var(--msg-border);
    border-radius:999px;padding:3px 11px}

/* ── System card — posted by the app, not typed by an admin ── */
.msg-system{align-self:center;max-width:76%;background:var(--msg-panel-alt);
    border:1px solid var(--msg-border);border-left:3px solid var(--msg-brand);
    border-radius:12px;padding:10px 13px}
.msg-system-head{display:flex;align-items:center;gap:5px;font-size:10px;font-weight:700;
    text-transform:uppercase;letter-spacing:.06em;color:var(--msg-brand);margin-bottom:5px}
.msg-system-body{font-size:12.5px;line-height:1.5;color:var(--msg-text);white-space:pre-line}
.msg-system-time{font-size:10px;color:var(--msg-text-3);margin-top:6px}

/* ── Photos inside a bubble ── */
.msg-photos{display:grid;gap:4px;margin-bottom:5px}
.msg-photos.n1{grid-template-columns:1fr}
.msg-photos.n2,.msg-photos.n4{grid-template-columns:1fr 1fr}
.msg-photos.n3,.msg-photos.n5{grid-template-columns:1fr 1fr 1fr}
.msg-photo{display:block;width:100%;border-radius:9px;cursor:zoom-in;
    background:var(--msg-bubble-in)}
.msg-photos.n1 .msg-photo{max-height:250px;width:auto;max-width:100%;object-fit:contain}
.msg-photos:not(.n1) .msg-photo{aspect-ratio:1;height:100%;object-fit:cover}

/* ── Staged photos, before sending ── */
.msg-tray{display:flex;flex-wrap:wrap;gap:7px;padding:11px 14px 0}
.msg-tray-item{position:relative;width:56px;height:56px;border-radius:9px;overflow:hidden;
    border:1px solid var(--msg-border);background:var(--msg-bubble-in)}
.msg-tray-item img{width:100%;height:100%;object-fit:cover;display:block}
.msg-tray-x{position:absolute;top:2px;right:2px;width:17px;height:17px;border:none;padding:0;
    border-radius:50%;background:rgba(0,0,0,.62);color:#fff;font-size:12px;line-height:16px;
    cursor:pointer;text-align:center}
.msg-tray-x:hover{background:rgba(0,0,0,.85)}
.msg-attach{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;
    flex-shrink:0;border-radius:10px;cursor:pointer;color:var(--msg-text-2);
    background:var(--msg-input-bg);border:1px solid var(--msg-border);transition:all .15s}
.msg-attach:hover{color:var(--msg-brand);border-color:var(--msg-brand)}
.msg-attach input{display:none}
.msg-error{padding:6px 14px 0;font-size:11.5px;color:var(--msg-brand-hi)}
.msg-hint{padding:6px 14px 0;font-size:11px;color:var(--msg-text-3)}

/* Drop target feedback — the whole thread panel accepts a dragged photo. */
.msg-main.is-dragging{outline:2px dashed var(--msg-brand);outline-offset:-6px}

/* ── Lightbox ── */
.msg-lightbox{position:fixed;inset:0;z-index:60;display:flex;align-items:center;
    justify-content:center;background:rgba(0,0,0,.88);cursor:zoom-out}
.msg-lightbox img{max-width:92vw;max-height:88vh;border-radius:6px;cursor:default}
.msg-lightbox-close{position:absolute;top:16px;right:22px;background:none;border:none;
    color:#fff;font-size:30px;line-height:1;cursor:pointer;opacity:.75}
.msg-lightbox-close:hover{opacity:1}

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
            <p class="msg-side-title">{{ $composing ? 'New Message' : 'Conversations' }}</p>
            <div style="display:flex;align-items:center;gap:7px">
                @if(! $composing && $totalUnread > 0)
                    <span class="msg-side-count">{{ $totalUnread }} new</span>
                @endif
                <button wire:click="toggleCompose" class="msg-new-btn {{ $composing ? 'is-open' : '' }}">
                    @if($composing)
                        Cancel
                    @else
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
                            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        New
                    @endif
                </button>
            </div>
        </div>

        @if($composing)
            {{-- Customer picker — lets an admin write first --}}
            <div class="msg-search-wrap">
                <input
                    wire:model.live.debounce.300ms="customerSearch"
                    type="text"
                    placeholder="Search customers by name or email..."
                    class="msg-search"
                />
            </div>
            <div class="msg-side-list">
                @forelse($this->customerList() as $customer)
                    <div wire:click="startConversation({{ $customer['user_id'] }})" class="msg-conv">
                        <div class="msg-avatar">{{ $initials($customer['name']) }}</div>
                        <div class="msg-conv-body">
                            <div class="msg-conv-name">{{ $customer['name'] }}</div>
                            <div class="msg-cust-email">{{ $customer['email'] }}</div>
                        </div>
                    </div>
                @empty
                    <div class="msg-empty-side">
                        {{ trim($customerSearch) !== '' ? 'No customers match that search.' : 'No customers yet.' }}
                    </div>
                @endforelse
            </div>
        @else
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
        @endif
    </div>

    {{-- Message thread --}}
    <div class="msg-main">
        @if($selectedUserId)
            <div class="msg-main-head">
                @php $headName = $selectedUserName ?? ($selectedConv['name'] ?? 'Conversation'); @endphp
                <div class="msg-avatar" style="width:34px;height:34px;font-size:12.5px">{{ $initials($headName) }}</div>
                <div>
                    <p class="msg-main-name">{{ $headName }}</p>
                    <p class="msg-main-sub">Customer conversation</p>
                </div>
            </div>

            {{-- Messages --}}
            <div id="msg-thread" data-user="{{ $selectedUserId }}">
                @if($thread->isEmpty())
                    <p class="msg-thread-hint">
                        No messages with {{ $headName }} yet.<br>
                        Write below to start the conversation.
                    </p>
                @endif
                @php $lastDay = null; @endphp
                @foreach($thread as $message)
                    @php
                        $isStoreSide = in_array($message->sender_id, $adminIds);
                        $day         = $message->created_at->toDateString();
                    @endphp

                    @if($day !== $lastDay)
                        <div class="msg-day">
                            <span>
                                @if($message->created_at->isToday()) Today
                                @elseif($message->created_at->isYesterday()) Yesterday
                                @else {{ $message->created_at->format('D, M j, Y') }}
                                @endif
                            </span>
                        </div>
                        @php $lastDay = $day; @endphp
                    @endif

                    @if($message->isOrderUpdate())
                        {{-- Posted by the app, through whichever admin account is
                             active — so it is not drawn as that person speaking.
                             Only messages written since the kind column landed are
                             marked; older order updates stay as bubbles, because
                             recognising them would mean pattern-matching free text. --}}
                        <div class="msg-system">
                            <div class="msg-system-head">
                                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>
                                </svg>
                                Order update
                            </div>
                            <div class="msg-system-body">{{ $message->content }}</div>
                            <div class="msg-system-time">{{ $message->created_at->format('M d, h:i A') }}</div>
                        </div>
                    @else
                        <div class="msg-row {{ $isStoreSide ? 'out' : 'in' }}">
                            <div class="msg-bubble">
                                @if($isStoreSide && $message->sender_id !== auth()->id())
                                    <div class="msg-sender">{{ $message->sender?->first_name ?? 'Admin' }}</div>
                                @endif

                                @if($message->attachments->isNotEmpty())
                                    <div class="msg-photos n{{ $message->attachments->count() }}">
                                        @foreach($message->attachments as $attachment)
                                            {{-- Served by the app behind the panel's own
                                                 gate, never from a public storage path. --}}
                                            <img class="msg-photo" loading="lazy"
                                                 src="{{ route('filament.admin.messages.attachment', $attachment) }}"
                                                 alt="{{ $attachment->original_name }}"
                                                 @if($attachment->width && $attachment->height)
                                                     width="{{ $attachment->width }}"
                                                     height="{{ $attachment->height }}"
                                                 @endif
                                            />
                                        @endforeach
                                    </div>
                                @endif

                                @if(filled($message->content))
                                    {{ $message->content }}
                                @endif

                                <div class="msg-time">{{ $message->created_at->format('M d, h:i A') }}</div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            {{-- Reply box --}}
            @error('photos')   <div class="msg-error">{{ $message }}</div> @enderror
            @error('photos.*') <div class="msg-error">{{ $message }}</div> @enderror

            @if($photos)
                <div class="msg-tray">
                    @foreach($photos as $index => $photo)
                        <div class="msg-tray-item">
                            @if(is_object($photo) && method_exists($photo, 'isPreviewable') && $photo->isPreviewable())
                                {{-- Guarded: Livewire throws rather than returning
                                     null for a file it cannot preview. --}}
                                <img src="{{ $photo->temporaryUrl() }}" alt="">
                            @endif
                            <button type="button" class="msg-tray-x"
                                    wire:click="removePhoto({{ $index }})"
                                    title="Remove">&times;</button>
                        </div>
                    @endforeach
                </div>
            @endif

            <div wire:loading wire:target="photos" class="msg-hint">Uploading…</div>

            <div class="msg-composer">
                {{-- Photos can also be pasted, or dragged onto the thread — both
                     route through this same input (see the script below), so
                     Livewire only ever sees one way of receiving a file. --}}
                <label class="msg-attach" title="Attach photos">
                    <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                    </svg>
                    <input id="msg-photo-input" type="file" wire:model="photos"
                           multiple accept="image/jpeg,image/png,image/webp">
                </label>
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

(function () {
    // Bound once for the life of the tab. Filament navigates as an SPA, so
    // this script runs again every time the page is revisited — without the
    // guard each visit would add another set of document listeners and a
    // single paste would stage the same photo several times over.
    if (window.__ncmMessageAttachmentsBound) return;
    window.__ncmMessageAttachmentsBound = true;

    const MAX_PHOTOS = 5;
    const input  = () => document.getElementById('msg-photo-input');
    const panel  = () => document.querySelector('.msg-main');

    // Paste and drag-drop both end here: fill the real file input and fire a
    // change event, so Livewire's own upload path is the only one that ever
    // runs. Any other route would be a second way to receive a file, and a
    // second place to get the validation wrong.
    function handOff(fileList) {
        const target = input();
        if (! target || ! fileList) return false;

        const images = Array.from(fileList)
            .filter(file => file.type.startsWith('image/'))
            .slice(0, MAX_PHOTOS);

        if (! images.length) return false;

        const bag = new DataTransfer();
        images.forEach(file => bag.items.add(file));
        target.files = bag.files;
        target.dispatchEvent(new Event('change', { bubbles: true }));

        return true;
    }

    document.addEventListener('paste', function (event) {
        if (event.clipboardData && handOff(event.clipboardData.files)) {
            event.preventDefault();
        }
    });

    // Drag state is counted, not toggled: dragging across a child element
    // fires dragleave on the parent, and a plain toggle flickers the outline
    // the whole way across the panel.
    let depth = 0;
    const carriesFiles = (event) =>
        event.dataTransfer && Array.from(event.dataTransfer.types || []).includes('Files');

    document.addEventListener('dragenter', function (event) {
        if (! carriesFiles(event) || ! input()) return;
        depth++;
        panel()?.classList.add('is-dragging');
    });

    document.addEventListener('dragover', function (event) {
        if (carriesFiles(event) && input()) event.preventDefault();
    });

    document.addEventListener('dragleave', function () {
        if (--depth <= 0) {
            depth = 0;
            panel()?.classList.remove('is-dragging');
        }
    });

    document.addEventListener('drop', function (event) {
        depth = 0;
        panel()?.classList.remove('is-dragging');
        if (handOff(event.dataTransfer && event.dataTransfer.files)) {
            event.preventDefault();
        }
    });

    // Lightbox. Delegated, because Livewire replaces the thread's DOM on
    // every five-second poll and a bound handler would go with it.
    document.addEventListener('click', function (event) {
        const photo = event.target.closest?.('.msg-photo');
        if (! photo) return;

        const box = document.createElement('div');
        box.className = 'msg-lightbox';
        box.innerHTML = '<button class="msg-lightbox-close" aria-label="Close">&times;</button>';

        const full = document.createElement('img');
        full.src = photo.src;
        full.alt = photo.alt || '';
        box.appendChild(full);

        const onKey = (e) => { if (e.key === 'Escape') close(); };
        function close() {
            box.remove();
            document.removeEventListener('keydown', onKey);
        }

        box.addEventListener('click', (e) => { if (e.target !== full) close(); });
        document.addEventListener('keydown', onKey);
        document.body.appendChild(box);
    });
})();
</script>
</x-filament-panels::page>
