# Lena speech playback

Signed-in web chat requests `POST /api/dispatch-chat/speech` with `text` (at most 2,000 characters) and `lang` (`en`, `de`, `bs`, `hr`, `sr`). The route uses Sanctum and a 30-request-per-minute throttle. It does not save audio or change database records.

The backend uses the existing `OPENROUTER_API_KEY`, Gemini `google/gemini-3.1-flash-tts-preview`, and the female **Kore** voice. The provider detects the language from the supplied text. Bosnian uses the same voice as Croatian and Serbian. Gemini returns 24 kHz mono 16-bit PCM; the backend adds a WAV header and returns private, non-cacheable audio. Provider usage is billed to the existing OpenRouter account. The model is a preview model.

Deploy the backend route/controller/service with the frontend changes. No migration, database change, additional provider key, or client voice installation is required. Follow the normal deployment process for refreshing any route/config caches. `OPENROUTER_SPEECH_MODEL` is optional and must identify a model compatible with Kore and this PCM format.

The frontend splits long replies, plays WAV chunks sequentially, aborts pending requests when a new playback or microphone recording starts, and releases audio URLs. It does not silently fall back to installed voices. Browser autoplay policies still apply; use the play button if automatic playback is blocked.

Verification:

- `tests/Unit/LenaSpeechTest.php`: fake provider responses, female voice selection, WAV framing, and provider errors; no application/database bootstrap.
- `tests/speech-live.php`: explicitly run live-provider smoke test for five short non-sensitive samples. This consumes provider usage and writes WAV files under `storage/app/private/speech-smoke/`.
- Frontend `tests/serverSpeechPlayback.test.ts`: chunking, cancellation, failure handling.
- Frontend `tests/speech-browser.cjs`: uses the generated WAV samples through the actual chat component with deterministic microphone events. It tests real media decoding/playback without depending on OS speech voices.

Sources: [OpenRouter TTS](https://openrouter.ai/docs/guides/overview/multimodal/tts), [Google Gemini TTS voices](https://docs.cloud.google.com/text-to-speech/docs/gemini-tts).
