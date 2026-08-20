<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\OpenRouterDispatchAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DispatchChatController extends Controller
{
    public function store(Request $request, OpenRouterDispatchAssistant $assistant): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer', 'exists:conversations,id'],
        ]);

        if (! config('services.openrouter.api_key')) {
            return $this->unavailable('AI dispatcher is not configured on the server.');
        }

        $aiDispatcherId = User::query()->where('username', 'ai_dispatcher')->value('id');
        if (! $aiDispatcherId) {
            return $this->unavailable('AI dispatcher is not configured.');
        }

        $conversation = Conversation::query()->with(['messages', 'freightLoad.stops'])->findOrFail($validated['conversation_id']);

        $load = $conversation->freightLoad;
        $origin = $load?->stops->firstWhere('type', 'pickup')?->city;
        $destination = $load?->stops->firstWhere('type', 'delivery')?->city;

        $systemPrompt = 'You are the AI Dispatcher for a freight logistics platform, chatting with a dispatcher, driver, or customer about one specific load. '
            .'Answer questions about this load\'s status, ETA, and route, and help draft short updates when asked. Keep replies concise (2-3 sentences) and professional, and stay in character as the dispatcher for this load only.'
            .($load ? sprintf(
                ' Load details: title "%s", status "%s", route %s -> %s.',
                $load->title,
                $load->status,
                $origin ?: 'unknown origin',
                $destination ?: 'unknown destination'
            ) : '');

        $history = $conversation->messages
            ->sortBy('sent_at')
            ->map(fn (Message $message) => [
                'role' => $message->sender_user_id === $aiDispatcherId ? 'assistant' : 'user',
                'content' => $message->body,
            ])
            ->values()
            ->all();

        try {
            $reply = $assistant->reply($systemPrompt, $history);
        } catch (RuntimeException $exception) {
            return $this->unavailable($exception->getMessage());
        }

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $aiDispatcherId,
            'body' => $reply,
            'sent_at' => now(),
        ]);
        $conversation->update(['last_message_at' => $message->sent_at]);
        $conversation->participants()->syncWithoutDetaching([$aiDispatcherId]);
        $message->load('sender');

        return response()->json([
            'message' => 'Reply generated.',
            'data' => (new EntityResource($message))->resolve($request),
            'meta' => [],
            'errors' => [],
        ], 201);
    }

    private function unavailable(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => null, 'meta' => [], 'errors' => []], 503);
    }
}
