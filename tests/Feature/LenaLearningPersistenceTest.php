<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\LenaLearningSession;
use App\Models\Message;
use App\Services\LenaLearning;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LenaLearningPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); // Base TestCase verifies effective SQLite :memory: before these writes.
        $this->mock(\App\Services\LenaLearningTutor::class, function ($mock): void {
            $mock->shouldReceive('generate')->andReturnUsing(function ($conversation, $aiId, $locale, $state) {
                $turn = ($state['turns'] ?? 0) + 1;
                return ['AI learning question '.$turn, ['mode' => 'ai', 'phase' => 'adaptive', 'turns' => $turn]];
            });
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->string('username'); $table->unsignedBigInteger('role_id')->nullable(); $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id(); $table->string('name');
        });
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('load_id')->nullable(); $table->unsignedBigInteger('load_draft_id')->nullable();
            $table->boolean('canvas')->default(false); $table->string('subject')->nullable();
            $table->timestamp('last_message_at')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('conversation_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('conversation_id'); $table->unsignedBigInteger('user_id');
            $table->timestamp('last_read_at')->nullable(); $table->timestamps();
        });
        Schema::create('messages', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('conversation_id'); $table->unsignedBigInteger('sender_user_id');
            $table->text('body'); $table->timestamp('sent_at'); $table->timestamps();
        });
        (require __DIR__.'/../../database/migrations/2026_10_06_120000_create_lena_learning_sessions_table.php')->up();
        \Illuminate\Support\Facades\DB::table('users')->insert([
            ['id' => 1, 'username' => 'learner'], ['id' => 2, 'username' => 'ai_dispatcher'], ['id' => 3, 'username' => 'other'],
        ]);
    }

    private function conversation(): Conversation
    {
        $conversation = Conversation::query()->create(['created_by_user_id' => 1]);
        $conversation->participants()->attach([1, 2]);
        return $conversation;
    }

    private function input(Conversation $conversation, string $body): void
    {
        Message::query()->create(['conversation_id' => $conversation->id, 'sender_user_id' => 1, 'body' => $body, 'sent_at' => now()]);
    }

    public function test_retry_is_idempotent_and_resume_preserves_progress(): void
    {
        $conversation = $this->conversation();
        $this->input($conversation, '[[LENA_ACTION:learn]]');
        $service = new LenaLearning;
        $reply = $service->reply($conversation, 1, 2, 'en');
        $this->assertSame($reply->id, $service->reply($conversation, 1, 2, 'en')->id);
        $this->assertSame(2, Message::query()->count());
        $this->input($conversation, '[[LENA_LEARN:assessment:route:b]]');
        $service->reply($conversation, 1, 2, 'en');
        $this->input($conversation, '[[LENA_ACTION:learn]]');
        $reply = $service->reply($conversation, 1, 2, 'bs');
        $state = LenaLearningSession::query()->firstOrFail()->state;
        $this->assertSame(3, $state['turns']);
        $this->assertSame('ai', $state['mode']);
        $this->assertSame('AI learning question 3', $reply->body);
        $this->assertFalse($conversation->fresh()->canvas);
    }

    public function test_answer_sent_later_advances_to_next_question(): void
    {
        $conversation = $this->conversation();
        config(['services.openrouter.api_key' => null]);
        $this->withoutMiddleware()->actingAs(\App\Models\User::query()->findOrFail(1));
        $this->input($conversation, '[[LENA_ACTION:learn]]');
        $this->postJson('/api/dispatch-chat', ['conversation_id' => $conversation->id, 'lang' => 'en'])->assertCreated();
        $this->travel(30)->seconds();
        $this->input($conversation, '[[LENA_LEARN:assessment:route:b]]');
        $response = $this->postJson('/api/dispatch-chat', ['conversation_id' => $conversation->id, 'lang' => 'en'])->assertCreated();
        $this->assertSame('AI learning question 2', $response->json('data.body'));
        $session = LenaLearningSession::query()->firstOrFail();
        $this->assertSame(2, $session->state['turns']);
        $this->assertSame('ai', $session->state['mode']);
        $this->assertSame($response->json('data.id'), $this->postJson('/api/dispatch-chat', ['conversation_id' => $conversation->id, 'lang' => 'en'])->assertCreated()->json('data.id'));
    }

    public function test_other_user_cannot_access_or_mutate_learning(): void
    {
        $conversation = $this->conversation();
        $this->input($conversation, '[[LENA_ACTION:learn]]');
        try {
            (new LenaLearning)->reply($conversation, 3, 2, 'en');
            $this->fail('Other user was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(0, LenaLearningSession::query()->count());
        }
    }

    public function test_shared_human_conversation_is_rejected(): void
    {
        $conversation = $this->conversation();
        $conversation->participants()->attach(3);
        $this->input($conversation, '[[LENA_ACTION:learn]]');
        $this->expectException(HttpException::class);
        (new LenaLearning)->reply($conversation, 1, 2, 'en');
    }

    public function test_load_conversation_is_rejected_before_creating_progress(): void
    {
        $conversation = $this->conversation();
        $conversation->update(['load_id' => 99]);
        $this->expectException(HttpException::class);
        (new LenaLearning)->reply($conversation, 1, 2, 'en');
    }

    public function test_existing_dispatch_endpoint_runs_learning_without_llm_or_freight_queries(): void
    {
        $conversation = $this->conversation();
        $this->input($conversation, '[[LENA_ACTION:learn]]');
        config(['services.openrouter.api_key' => null]);
        $this->withoutMiddleware()->actingAs(\App\Models\User::query()->findOrFail(1))
            ->postJson('/api/dispatch-chat', ['conversation_id' => $conversation->id, 'lang' => 'en'])
            ->assertCreated()->assertJsonPath('data.sender_user_id', 2)
            ->assertJsonPath('data.body', (new LenaLearning)->reply($conversation, 1, 2, 'en')->body);
        $this->assertSame(1, LenaLearningSession::query()->count());
    }

    public function test_tutor_receives_actual_legal_document_and_accepts_free_text(): void
    {
        $conversation = $this->conversation();
        $this->input($conversation, 'Prepare me for customs work in Bosnia and Herzegovina.');
        $source = app(\App\Services\LegalSourceCatalog::class)->sources()[0];
        $this->assertFileExists(app(\App\Services\LegalSourceCatalog::class)->path($source));
        config(['services.openrouter.api_key' => 'test-key']);
        $call = 0;
        $this->mock(\App\Services\OpenRouterDispatchAssistant::class, function ($mock) use (&$call, $source): void {
            $mock->shouldReceive('reply')->twice()->andReturnUsing(function ($prompt, $history) use (&$call, $source) {
                $call++;
                if ($call === 1) return json_encode(['source_ids' => [$source['id']]]);
                $this->assertStringContainsString('Skip material already demonstrated', $prompt);
                $this->assertStringContainsString('Prepare me for customs work', $history[0]['content']);
                $document = $history[count($history) - 1]['content'][1];
                $this->assertSame('file', $document['type']);
                $this->assertStringStartsWith('data:application/pdf;base64,', $document['file']['file_data']);
                $this->assertStringContainsString($source['title'], $history[count($history) - 1]['content'][0]['text']);
                return 'A new practical question. [[LEGAL_SOURCES:'.$source['id'].']]';
            });
        });
        [$body, $state] = (new \App\Services\LenaLearningTutor)->generate($conversation, 2, 'en', ['phase' => 'lesson', 'index' => 0, 'attempts' => [['correct' => true]]]);
        $this->assertStringContainsString('A new practical question', $body);
        $this->assertSame([$source['id']], $state['source_ids']);
        $this->assertTrue($state['legacy_progress']['attempts'][0]['correct']);
    }

    public function test_malformed_source_json_is_retried_in_structured_mode(): void
    {
        $conversation = $this->conversation();
        $this->input($conversation, 'Start learning.');
        config(['services.openrouter.api_key' => 'test-key']);
        $call = 0;
        $this->mock(\App\Services\OpenRouterDispatchAssistant::class, function ($mock) use (&$call): void {
            $mock->shouldReceive('reply')->times(3)->andReturnUsing(function ($prompt, $history, $conversationId, $attachment, $service, $web = false, $format = null) use (&$call) {
                $call++;
                if ($call <= 2) {
                    $this->assertSame('json_schema', $format['type']);
                    $this->assertTrue($format['json_schema']['strict']);
                    $this->assertSame(['source_ids'], $format['json_schema']['schema']['required']);
                }
                return match ($call) { 1 => 'Which country do you want?', 2 => '{"source_ids":[]}', default => 'What country should we study?' };
            });
        });
        [$body, $state] = (new \App\Services\LenaLearningTutor)->generate($conversation, 2, 'en', ['mode' => 'ai']);
        $this->assertSame('What country should we study?', $body);
        $this->assertSame([], $state['source_ids']);
    }

    public function test_unavailable_documents_are_excluded_from_prompt_and_schema(): void
    {
        $conversation = $this->conversation();
        $this->input($conversation, 'I am learning customs in Bosnia.');
        config(['services.openrouter.api_key' => 'test-key']);
        $catalog = new \App\Services\LegalSourceCatalog;
        $valid = $catalog->sources()[0];
        $validPath = $catalog->path($valid);
        $large = tmpfile();
        $this->assertNotFalse($large);
        try {
            ftruncate($large, 12 * 1024 * 1024 + 1);
            $largePath = stream_get_meta_data($large)['uri'];
            $entries = [$valid, [...$valid, 'id' => 'external-link', 'stored' => false],
                [...$valid, 'id' => 'missing-file'], [...$valid, 'id' => 'oversized-file']];
            $this->mock(\App\Services\LegalSourceCatalog::class, function ($mock) use ($entries, $validPath, $largePath): void {
                $mock->shouldReceive('sources')->andReturn($entries);
                $mock->shouldReceive('path')->andReturnUsing(fn ($source) => match ($source['id']) {
                    'missing-file' => $validPath.'.missing', 'oversized-file' => $largePath, default => $validPath,
                });
            });
            $this->mock(\App\Services\OpenRouterDispatchAssistant::class, function ($mock) use ($valid): void {
                $mock->shouldReceive('reply')->withArgs(function ($prompt, $history, $id, $attachment, $service, $web, $format) use ($valid) {
                    $this->assertSame([$valid['id']], $format['json_schema']['schema']['properties']['source_ids']['items']['enum']);
                    foreach (['external-link', 'missing-file', 'oversized-file'] as $excluded) $this->assertStringNotContainsString($excluded, $prompt);
                    return $service === 'learning_sources';
                })->once()->andReturn('{"source_ids":[]}');
                $mock->shouldReceive('reply')->withArgs(fn ($prompt, $history, $id, $attachment, $service) => $service === 'learning_tutor')
                    ->once()->andReturn('Let us clarify your role.');
            });
            [$body] = (new \App\Services\LenaLearningTutor)->generate($conversation, 2, 'en', ['mode' => 'ai']);
            $this->assertSame('Let us clarify your role.', $body);
        } finally {
            fclose($large);
        }
    }

    public function test_unavailable_ai_does_not_advance_or_create_learning_state(): void
    {
        $conversation = $this->conversation();
        $this->input($conversation, '[[LENA_ACTION:learn]]');
        $this->app->instance(\App\Services\LenaLearningTutor::class, new \App\Services\LenaLearningTutor);
        config(['services.openrouter.api_key' => null]);
        $this->withoutMiddleware()->actingAs(\App\Models\User::findOrFail(1))
            ->postJson('/api/dispatch-chat', ['conversation_id' => $conversation->id, 'lang' => 'en'])->assertStatus(503);
        $this->assertSame(0, LenaLearningSession::query()->count());
        $this->assertSame(1, Message::query()->count());
    }

    public function test_dispatch_endpoint_rejects_nonparticipant(): void
    {
        $conversation = $this->conversation();
        $this->input($conversation, '[[LENA_ACTION:learn]]');
        $this->withoutMiddleware()->actingAs(\App\Models\User::query()->findOrFail(3))
            ->postJson('/api/dispatch-chat', ['conversation_id' => $conversation->id, 'lang' => 'en'])
            ->assertForbidden();
        $this->assertSame(0, LenaLearningSession::query()->count());
    }
}
