<?php

namespace Tests\Unit;

use App\Services\AiCallLogger;
use App\Services\OpenRouterDispatchAssistant;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

class OpenRouterDispatchAssistantTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_image_mime_and_base64_slashes_are_literal_on_the_wire(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository(['services' => ['openrouter' => [
            'api_key' => 'test-key', 'model' => 'test-model', 'url' => 'https://example.test/chat',
        ]]]));
        $container->instance(Factory::class, new Factory);
        Facade::setFacadeApplication($container);
        Http::preventStrayRequests();
        $history = [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Provjeri "račun" i slike.'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,ab/c+d==']],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,ef/g+h==']],
        ]]];
        Http::fake(function ($request) use ($history) {
            $this->assertStringContainsString('data:image/png;base64,ab/c+d==', $request->body());
            $this->assertStringContainsString('data:image/jpeg;base64,ef/g+h==', $request->body());
            $this->assertStringNotContainsString('image\\/png', $request->body());
            $payload = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($history[0], $payload['messages'][1]);
            $this->assertTrue($request->hasHeader('Content-Type', 'application/json'));
            return Http::response(['choices' => [['message' => ['content' => 'Slike su pregledane.']]]]);
        });
        $logger = $this->createMock(AiCallLogger::class);
        $logger->expects($this->once())->method('record')->with($this->callback(fn ($row) =>
            $row['is_success'] === true && $row['conversation_id'] === 259
        ));
        $this->assertSame('Slike su pregledane.', (new OpenRouterDispatchAssistant($logger))->reply('System', $history, 259, true));
        Http::assertSentCount(1);
    }
}
