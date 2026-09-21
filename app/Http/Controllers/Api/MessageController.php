<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use App\Services\MessageAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    // All admin-side user ids. Archived admins are included so threads
    // that historically went to them keep their message history.
    private function adminIds(): array
    {
        return User::query()->admins()->pluck('id')->all();
    }

    /**
     * Conversations, one row per counterpart.
     *
     * Three queries, whatever the message volume. The previous version read
     * EVERY message the user had ever sent or received into PHP - with both
     * user relations eager-loaded - and grouped the collection in memory, so
     * its cost grew with the history rather than with the number of people in
     * it. Adding attachments to that load would have made a bad shape worse.
     */
    public function conversations(Request $request)
    {
        $userId = $request->user()->id;

        // Ids come straight from the users table as integers; cast anyway,
        // because this goes into the SQL text rather than a binding (a CASE
        // expression has to appear identically in SELECT and GROUP BY).
        $counterpart = 'CASE WHEN sender_id = '.(int) $userId.' THEN receiver_id ELSE sender_id END';

        $summaries = Message::query()
            ->selectRaw("{$counterpart} as other_user_id")
            ->selectRaw('MAX(id) as last_id')
            ->selectRaw('SUM(CASE WHEN receiver_id = ? AND is_read = 0 THEN 1 ELSE 0 END) as unread_count', [$userId])
            ->where(fn ($q) => $q->where('sender_id', $userId)->orWhere('receiver_id', $userId))
            ->groupBy(DB::raw($counterpart))
            ->get();

        // The latest message of each conversation, with its attachments - the
        // preview line needs them whenever the message is a photo and nothing
        // else. One query for all of them; loading them lazily here is an
        // N+1 across the entire inbox.
        $latest = Message::with('attachments')
            ->whereIn('id', $summaries->pluck('last_id'))
            ->get()
            ->keyBy('id');

        $others = User::whereIn('id', $summaries->pluck('other_user_id'))
            ->get()
            ->keyBy('id');

        return response()->json(
            $summaries
                ->map(function ($row) use ($latest, $others) {
                    $message = $latest->get($row->last_id);
                    $otherUser = $others->get($row->other_user_id);

                    if (! $message || ! $otherUser) {
                        return null;
                    }

                    return [
                        'user_id'         => $otherUser->id,
                        'name'            => $otherUser->first_name.' '.$otherUser->last_name,
                        'role'            => $otherUser->role,
                        'last_message'    => $message->preview(),
                        'last_message_at' => $message->created_at,
                        'unread_count'    => (int) $row->unread_count,
                    ];
                })
                ->filter()
                ->sortByDesc('last_message_at')
                ->values()
        );
    }

    // Get the message thread between a customer and the store.
    // The store inbox is shared: a customer's thread includes replies from
    // ANY admin, and an admin sees the customer's messages to any admin —
    // regardless of which specific admin account they were addressed to.
    public function thread(Request $request, $otherUserId)
    {
        $user = $request->user();
        $adminIds = $this->adminIds();

        $customerId = in_array($user->id, $adminIds) ? (int) $otherUserId : $user->id;

        $messages = Message::where(function ($q) use ($customerId, $adminIds) {
            $q->where('sender_id', $customerId)
                ->whereIn('receiver_id', $adminIds);
        })
            ->orWhere(function ($q) use ($customerId, $adminIds) {
                $q->whereIn('sender_id', $adminIds)
                    ->where('receiver_id', $customerId);
            })
            // `attachments` eager-loaded for the obvious reason: a thread is
            // the one place that renders every message, so lazy loading here
            // is one query per bubble.
            ->with(['sender', 'receiver', 'attachments'])
            ->orderBy('created_at', 'asc')
            ->get();

        // Mark incoming messages as read
        if (in_array($user->id, $adminIds)) {
            Message::where('sender_id', $customerId)
                ->whereIn('receiver_id', $adminIds)
                ->where('is_read', false)
                ->update(['is_read' => true]);
        } else {
            Message::whereIn('sender_id', $adminIds)
                ->where('receiver_id', $user->id)
                ->where('is_read', false)
                ->update(['is_read' => true]);
        }

        return response()->json($messages);
    }

    /**
     * Send a message, with up to five photos.
     *
     * Multipart rather than a separate upload step, so one request is one
     * message: there is no state in which half an upload has produced a
     * bubble in the thread that the sender then has to explain.
     */
    public function send(Request $request)
    {
        // Before validation, because there may be nothing left to validate —
        // see MessageAttachmentService::requestWasTruncated().
        if (MessageAttachmentService::requestWasTruncated($request)) {
            return response()->json([
                'message' => 'Those photos were too large to upload. Try sending fewer at a time.',
                'errors' => ['attachments' => ['The upload exceeded the server limit.']],
            ], 413);
        }

        $validated = $request->validate(
            MessageAttachmentService::rules() + [
                'receiver_id' => ['required', 'exists:users,id'],
            ],
            MessageAttachmentService::validationMessages(),
        );

        $files = $request->file('attachments', []);

        if (MessageAttachmentService::totalKilobytes($files) > MessageAttachmentService::MAX_TOTAL_KB) {
            return response()->json([
                'message' => 'Those photos are too large to send together.',
                'errors' => ['attachments' => [
                    'Total upload must be under '.(MessageAttachmentService::MAX_TOTAL_KB / 1024).' MB.',
                ]],
            ], 422);
        }

        $message = MessageAttachmentService::send(
            senderId: $request->user()->id,
            receiverId: (int) $validated['receiver_id'],
            content: $validated['content'] ?? null,
            files: $files,
        );

        $message->load(['sender', 'receiver']);

        return response()->json([
            'message' => 'Message sent.',
            'data'    => $message,
        ], 201);
    }

    // Get an active admin user ID (so mobile app knows who to message).
    // Archived admins must never receive new messages.
    public function getAdmin()
    {
        $admin = User::activeAdmin();

        if (! $admin) {
            return response()->json(['message' => 'No admin found.'], 404);
        }

        return response()->json([
            'id'   => $admin->id,
            'name' => $admin->first_name.' '.$admin->last_name,
        ]);
    }

    // Mark a message as read
    public function markRead(Request $request, Message $message)
    {
        if ($message->receiver_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $message->update(['is_read' => true]);

        return response()->json(['message' => 'Message marked as read.']);
    }
}
