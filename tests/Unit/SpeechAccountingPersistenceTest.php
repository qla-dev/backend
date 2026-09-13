<?php

namespace Tests\Unit;

use App\Models\AiCallLog;
use App\Services\AiCallLogger;
use App\Services\SpeechUsageReconciler;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class SpeechAccountingPersistenceTest extends TestCase
{
    public function test_generations_persist_separately_and_reconciliation_updates_totals_without_duplicate_charges(): void
    {
        $container = new Container;
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $container->instance('auth', new class { public function id() { return 7; } });
        $db = new Manager($container);
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $db->bootEloquent();
        $connection = $db->getConnection();
        // Verify the effective connection before the first schema/record write.
        $effective = array_intersect_key($connection->getConfig(), array_flip(['driver', 'host', 'port', 'database'])) + ['host' => null, 'port' => null];
        $this->assertSame(['driver' => 'sqlite', 'database' => ':memory:', 'host' => null, 'port' => null], $effective);
        fwrite(STDOUT, 'Isolated accounting test connection: '.json_encode($effective).PHP_EOL);
        try {
            $connection->getSchemaBuilder()->create('ai_call_logs', function (Blueprint $table) {
                $table->id();
                $table->string('service');
                $table->integer('conversation_id');
                $table->integer('user_id');
                $table->string('generation_id');
                $table->boolean('is_success');
                $table->decimal('cost_usd', 12, 6)->nullable();
                $table->integer('total_tokens')->nullable();
                $table->json('request_payload')->nullable();
                $table->timestamps();
            });
            // No subscription table: trying to charge a plan message would fail this test.
            $logger = new AiCallLogger;
            foreach (['gen-one', 'gen-two'] as $id) {
                $logger->record(['service' => 'speech', 'conversation_id' => 94, 'generation_id' => $id, 'is_success' => true]);
            }
            $this->assertSame(2, AiCallLog::where('conversation_id', 94)->count());
            $this->assertSame([7, 7], AiCallLog::pluck('user_id')->all());
            $reconciler = new class extends SpeechUsageReconciler {
                public function fetch(string $generationId): ?array { return ['cost_usd' => 0.001304, 'total_tokens' => 69]; }
            };
            $reconciler->reconcilePending(AiCallLog::where('conversation_id', 999));
            $this->assertSame(2, AiCallLog::whereNull('cost_usd')->count());
            $reconciler->reconcilePending(AiCallLog::where('conversation_id', 94), 1);
            $this->assertSame(1, AiCallLog::whereNull('cost_usd')->count());
            $reconciler->reconcilePending(AiCallLog::where('conversation_id', 94));
            $reconciler->reconcile('gen-one');
            $this->assertSame(2, AiCallLog::count());
            $this->assertEqualsWithDelta(0.002608, AiCallLog::sum('cost_usd'), 0.0000001);
            $this->assertSame(138, (int) AiCallLog::sum('total_tokens'));
            $connection->getSchemaBuilder()->create('user_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->integer('user_id');
                $table->boolean('active');
                $table->integer('remaining_tokens');
                $table->timestamps();
            });
            $connection->table('user_subscriptions')->insert(['user_id' => 7, 'active' => true, 'remaining_tokens' => 10]);
            $container->instance('db', $db->getDatabaseManager());
            $container->instance('request', new \Illuminate\Http\Request(['input_mode' => 'voice']));
            $logger->record(['service' => 'dispatch_chat', 'conversation_id' => 94, 'generation_id' => 'voice-reply', 'is_success' => true]);
            $this->assertSame(8, (int) $connection->table('user_subscriptions')->value('remaining_tokens'));
            foreach (['audio-chunk', 'audio-replay'] as $id) {
                $logger->record(['service' => 'speech', 'conversation_id' => 94, 'generation_id' => $id, 'is_success' => true]);
            }
            $this->assertSame(8, (int) $connection->table('user_subscriptions')->value('remaining_tokens'));
            $this->assertSame('voice', AiCallLog::where('generation_id', 'voice-reply')->first()->request_payload['input_mode']);
            $container->instance('request', new \Illuminate\Http\Request(['input_mode' => 'text']));
            $logger->record(['service' => 'dispatch_chat', 'conversation_id' => 94, 'generation_id' => 'text-reply', 'is_success' => true]);
            $logger->record(['service' => 'guided_answer', 'conversation_id' => 94, 'generation_id' => 'button', 'is_success' => true]);
            $this->assertSame(7, (int) $connection->table('user_subscriptions')->value('remaining_tokens'));
            $container->instance('request', new \Illuminate\Http\Request(['input_mode' => 'voice']));
            $logger->record(['service' => 'guided_answer', 'conversation_id' => 94, 'generation_id' => 'guided-voice', 'is_success' => true]);
            $logger->record(['service' => 'dispatch_chat', 'conversation_id' => 94, 'generation_id' => 'failed-voice', 'is_success' => false]);
            $this->assertSame(5, (int) $connection->table('user_subscriptions')->value('remaining_tokens'));
        } finally {
            $connection->disconnect();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            Container::setInstance(null);
        }
    }
}
