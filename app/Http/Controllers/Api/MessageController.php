<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    // Get all conversations for the current user
    public function conversations(Request $request)
    {
        $userId = $request->user()->id;

        $conversations = Message::where('sender_id', $userId)
            ->orWhere('receiver_id', $userId)
            ->with(['sender', 'receiver'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy(function ($message) use ($userId) {
                return $message->sender_id === $userId
                    ? $message->receiver_id
                    : $message->sender_id;
            })
            ->map(function ($messages, $otherUserId) use ($userId) {
                $latest    = $messages->first();
                $otherUser = $latest->sender_id === $userId
                    ? $latest->receiver
                    : $latest->sender;

                return [
                    'user_id'        => $otherUser->id,
                    'name'           => $otherUser->first_name . ' ' . $otherUser->last_name,
                    'role'           => $otherUser->role,
                    'last_message'   => $latest->content,
                    'last_message_at' => $latest->created_at,
                    'unread_count'   => $messages->where('receiver_id', $userId)
                                                 ->where('is_read', false)
                                                 ->count(),
                ];
            })
            ->values();

        return response()->json($conversations);
    }

    // Get messages between current user and another user
    public function thread(Request $request, $otherUserId)
    {
        $userId = $request->user()->id;

        $messages = Message::where(function ($q) use ($userId, $otherUserId) {
                $q->where('sender_id', $userId)
                  ->where('receiver_id', $otherUserId);
            })
            ->orWhere(function ($q) use ($userId, $otherUserId) {
                $q->where('sender_id', $otherUserId)
                  ->where('receiver_id', $userId);
            })
            ->with(['sender', 'receiver'])
            ->orderBy('created_at', 'asc')
            ->get();

        // Mark messages as read
        Message::where('sender_id', $otherUserId)
            ->where('receiver_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json($messages);
    }

    // Send a message
    public function send(Request $request)
    {
        $validated = $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'content'     => 'required|string|max:2000',
        ]);

        $message = Message::create([
            'sender_id'   => $request->user()->id,
            'receiver_id' => $validated['receiver_id'],
            'content'     => $validated['content'],
            'timestamp'   => now(),
            'is_read'     => false,
        ]);

        $message->load(['sender', 'receiver']);

        return response()->json([
            'message' => 'Message sent.',
            'data'    => $message,
        ], 201);
    }

    // Get the admin user ID (so mobile app knows who to message)
    public function getAdmin()
    {
        $admin = User::where('role', 'admin')->first();

        if (!$admin) {
            return response()->json(['message' => 'No admin found.'], 404);
        }

        return response()->json([
            'id'   => $admin->id,
            'name' => $admin->first_name . ' ' . $admin->last_name,
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