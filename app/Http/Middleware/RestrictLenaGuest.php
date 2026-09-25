<?php

namespace App\Http\Middleware;

use App\Models\Conversation;
use App\Services\LenaGuest;
use Closure;
use Illuminate\Http\Request;

/** Guest tokens grant one conversation, never general account access. */
class RestrictLenaGuest
{
    public function handle(Request $request, Closure $next)
    {
        if (! LenaGuest::active($request->user())) return $next($request);
        $route = $request->route()->uri();
        $allowed = [
            'GET' => ['api/lena-guest/current', 'api/lena-realtime/status'],
            'POST' => ['api/lena-guest/publish', 'api/messages', 'api/dispatch-chat', 'api/dispatch-chat/skills',
                'api/lena-guided-answer', 'api/load-scans/text', 'api/lena-realtime/session',
                'api/lena-realtime/transcript', 'api/lena-realtime/usage', 'api/lena-realtime/mode-skills'],
        ];
        abort_unless(in_array($route, $allowed[$request->method()] ?? [], true), 403);
        $conversation = Conversation::query()->where('created_by_user_id', $request->user()->id)->firstOrFail();
        if ($request->has('conversation_id')) {
            abort_unless((int) $request->input('conversation_id') === $conversation->id, 403);
        }
        if ($request->isMethod('POST')) $request->merge(['conversation_id' => $conversation->id]);
        if ($route === 'api/messages') {
            abort_if($request->filled('sender_user_id') && (int) $request->input('sender_user_id') !== $request->user()->id, 403);
            $request->validate(['body' => ['required', 'string', 'max:5000']]);
            abort_if(str_contains((string) $request->input('body'), '[[LENA_ACTION:training'), 403);
            $request->merge(['sender_user_id' => $request->user()->id]);
        }
        if ($route === 'api/lena-realtime/mode-skills') {
            $request->validate(['mode' => ['required', 'in:freeroam,add,legal,free']]);
        }
        return $next($request);
    }
}
