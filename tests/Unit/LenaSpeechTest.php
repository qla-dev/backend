<?php

namespace Tests\Unit;

use App\Services\LenaSpeech;
use App\Services\AiCallLogger;
use App\Services\SpeechUsageReconciler;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// Pure service tests: no Laravel bootstrap, database connection or migrations.
class LenaSpeechTest extends TestCase
{
    private AiCallLogger $logger;
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(AiCallLogger::class);
        $this->logger->method("record")->willReturnCallback(function (array $row) { $this->rows[] = $row; });
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository(['services' => ['openrouter' => [
            'api_key' => 'test-key', 'speech_model' => 'google/gemini-3.1-flash-tts-preview',
        ]]]));
        $container->instance(Factory::class, new Factory);
        Facade::setFacadeApplication($container);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_female_voice_and_pcm_are_requested_and_wav_is_valid(): void
    {
        $pcm = str_repeat("\x01\x02", 24000);
        Http::fake(['*' => Http::response($pcm, 200, ['Content-Type' => 'audio/pcm;rate=24000;channels=1'])]);
        $wav = (new LenaSpeech($this->logger))->synthesize('Dobar dan.', 94, 'bs');
        $this->assertCount(1, $this->rows);
        $this->assertSame(94, $this->rows[0]['conversation_id']);
        $this->assertSame('speech', $this->rows[0]['service']);
        $this->assertTrue($this->rows[0]['is_success']);
        $this->assertSame('RIFF', substr($wav, 0, 4));
        $this->assertSame('WAVE', substr($wav, 8, 4));
        $this->assertSame(48000, unpack('Vsize', substr($wav, 40, 4))['size']);
        $this->assertSame($pcm, substr($wav, 44));
        Http::assertSent(fn ($request) => $request['voice'] === 'Kore' && $request['response_format'] === 'pcm' && $request['input'] === 'Dobar dan.');
    }

    public function test_provider_json_error_is_not_returned_as_audio(): void
    {
        Http::fake(['*' => Http::response(['error' => 'test'], 429)]);
        $this->expectException(RuntimeException::class);
        (new LenaSpeech($this->logger))->synthesize('Hello');
    }

    public function test_each_replay_or_chunk_logs_a_separate_generation(): void
    {
        Http::fake(['*' => Http::response(str_repeat("\x00\x01", 100), 200, ['Content-Type' => 'audio/pcm', 'X-Generation-Id' => 'gen-tts-test'])]);
        $speech = new LenaSpeech($this->logger);
        $speech->synthesize('One', 94, 'en');
        $speech->synthesize('Two', 94, 'en');
        $this->assertCount(2, $this->rows);
        $this->assertSame([94, 94], array_column($this->rows, 'conversation_id'));
        $this->assertSame('gen-tts-test', $speech->generationId);
        $this->assertNull($this->rows[0]['cost_usd']);
    }

    public function test_billing_uses_actual_cost_and_native_audio_tokens(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            'total_cost' => 0.001304, 'tokens_prompt' => 3, 'tokens_completion' => null,
            'native_tokens_prompt' => 4, 'native_tokens_completion' => 65, 'provider_name' => 'Google',
        ]])]);
        $attributes = (new SpeechUsageReconciler)->fetch('gen-tts-test');
        $this->assertSame(0.001304, $attributes['cost_usd']);
        $this->assertSame(69, $attributes['total_tokens']);
        $this->assertSame('Google', $attributes['provider']);
    }

    public function test_delayed_metadata_stays_pending_instead_of_becoming_free(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $this->assertNull((new SpeechUsageReconciler)->fetch('gen-tts-pending'));
    }
}
