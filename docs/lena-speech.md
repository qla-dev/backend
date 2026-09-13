# Lena speech playback

User allowance: a successful reply submitted with `input_mode: voice` costs **two LenaAI messages total**, including its text and audio. The dispatch/guided reply applies this charge once; speech generation, chunks, and replay do not add allowance charges. Typed dispatch replies cost one and scripted button answers remain free. Provider USD costs remain the actual provider amounts, without the user allowance multiplier. The Usage page shows a Voice messages card after LenaAI Messages, counting successful voice-submitted replies from this change onward; older speech logs lack the input mode needed to identify spoken turns reliably.

Signed-in web chat requests `POST /api/dispatch-chat/speech` with `text` (at most 2,000 characters), `lang` (`en`, `de`, `bs`, `hr`, `sr`), and the saved `conversation_id`. The route uses Sanctum, conversation participation checks, and a 30-request-per-minute throttle. It records one `speech` AI call for each generation, replay, or chunk, but does not save audio.

The backend uses the existing `OPENROUTER_API_KEY`, Gemini `google/gemini-3.1-flash-tts-preview`, and the female **Kore** voice. The provider detects the language from the supplied text. Bosnian uses the same voice as Croatian and Serbian. Gemini returns 24 kHz mono 16-bit PCM; the backend adds a WAV header and returns private, non-cacheable audio. Provider usage is billed to the existing OpenRouter account. The model is a preview model.

Deploy the backend route/controller/service and reconciliation command with the frontend changes. No schema migration, additional provider key, or client voice installation is required. Follow the normal deployment process for refreshing any route/config caches. `OPENROUTER_SPEECH_MODEL` is optional and must identify a model compatible with Kore and this PCM format.

Accounting captures `X-Generation-Id` immediately and fetches actual billing metadata from OpenRouter after returning audio. If metadata is not available yet, the cost stays null (pending), not a fabricated zero. Ensure the Laravel scheduler runs every minute: `lena:reconcile-speech-usage` fills pending costs and native token counts in the existing rows. AI Stats session totals and the usage service breakdown include these rows. Speech does not consume an additional subscription message. Older audio calls cannot be reconstructed automatically because their generation/conversation association was not recorded.

AI Stats also reconciles up to five pending voice entries matching its filters before returning the list, so recovery does not depend exclusively on the scheduler. Each batch stops starting lookups after ten seconds (an in-flight lookup may take another ten seconds). Unresolved entries rotate through the backlog; old entries no longer expire from scheduled recovery after seven days. Provider HTTP failures other than temporarily unavailable metadata (404), and reconciliation exceptions, are logged. Actual costs remain pending until the provider supplies them.

The frontend splits long replies, plays WAV chunks sequentially, aborts pending requests when a new playback or microphone recording starts, and releases audio URLs. It does not silently fall back to installed voices. Browser autoplay policies still apply; use the play button if automatic playback is blocked.

Verification:

- `tests/Unit/LenaSpeechTest.php`: fake provider responses, female voice selection, WAV framing, and provider errors; no application/database bootstrap.
- `tests/speech-live.php`: explicitly run live-provider smoke test for five short non-sensitive samples. This consumes provider usage and writes WAV files under `storage/app/private/speech-smoke/`.
- Frontend `tests/serverSpeechPlayback.test.ts`: chunking, cancellation, failure handling.
- Frontend `tests/speech-browser.cjs`: uses the generated WAV samples through the actual chat component with deterministic microphone events. It tests real media decoding/playback without depending on OS speech voices.

Sources: [OpenRouter TTS](https://openrouter.ai/docs/guides/overview/multimodal/tts), [Google Gemini TTS voices](https://docs.cloud.google.com/text-to-speech/docs/gemini-tts).
