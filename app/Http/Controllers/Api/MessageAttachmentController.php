<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesConversationAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Chat attachments (documents dropped into a LenaAI conversation) are persisted to real disk
// storage here instead of being embedded as base64 inside the message's JSON attachments column -
// store() saves the file once and hands back a stable reference; show() streams it back for the
// "open in a new tab" / "download" link in the chat UI, gated to conversation participants only.
class MessageAttachmentController extends Controller
{
    use ScopesConversationAccess;

    // Types a browser can render directly in a tab. Everything else (Word, HEIC/HEIF photos that
    // most browsers still can't decode inline, etc.) is served as a download instead.
    private const INLINE_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer', 'exists:conversations,id'],
            'file' => ['required', 'file', 'max:15360'],
        ]);

        if (! $this->userIsConversationParticipant((int) $validated['conversation_id'], $request->user()?->id)) {
            throw ValidationException::withMessages(['conversation_id' => ['You are not part of this conversation.']]);
        }

        $file = $request->file('file');
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin'));
        $filename = Str::uuid()->toString().'.'.$extension;
        $file->storeAs("chat-attachments/{$validated['conversation_id']}", $filename, 'local');

        return response()->json([
            'message' => 'Attachment stored.',
            'data' => [
                'path' => "{$validated['conversation_id']}/{$filename}",
                'name' => $file->getClientOriginalName(),
                'type' => $file->getClientMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
            ],
            'meta' => [],
            'errors' => [],
        ], 201);
    }

    public function show(Request $request, int $conversation, string $filename): StreamedResponse
    {
        abort_unless($this->userIsConversationParticipant($conversation, $request->user()?->id), 403);

        $path = "chat-attachments/{$conversation}/{$filename}";
        abort_unless(Storage::disk('local')->exists($path), 404);

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $disposition = in_array($extension, self::INLINE_EXTENSIONS, true) ? 'inline' : 'attachment';
        $downloadName = (string) $request->query('name', $filename);

        return Storage::disk('local')->response($path, $downloadName, [], $disposition);
    }
}
