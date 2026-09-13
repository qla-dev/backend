<?php

namespace App\Http\Controllers\Api;

use App\Services\LenaSpeech;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Throwable;

class LenaSpeechController extends Controller
{
    public function store(Request $request, LenaSpeech $speech)
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'lang' => ['required', 'in:en,de,bs,hr,sr'],
        ]);
        $text = trim(preg_replace('/\[\[[\s\S]*?\]\]/u', '', $validated['text']));
        if ($text === '') return response()->json(['message' => 'There is no text to read.'], 422);

        try {
            return response($speech->synthesize($text), 200, [
                'Content-Type' => 'audio/wav',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable $exception) {
            return response()->json(['message' => 'Speech is temporarily unavailable. Please try again.'], 502);
        }
    }
}
