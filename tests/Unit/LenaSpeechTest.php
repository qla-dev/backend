<?php

namespace Tests\Unit;

use App\Services\LenaSpeech;
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
    protected function setUp(): void
    {
        parent::setUp();
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
        $wav = (new LenaSpeech)->synthesize('Dobar dan.');
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
        (new LenaSpeech)->synthesize('Hello');
    }
}
