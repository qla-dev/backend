<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\LoadDraft;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LenaGuestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $effective = app('db')->connection()->getConfig();
        if (($effective['driver'] ?? null) !== 'sqlite' || ($effective['database'] ?? null) !== ':memory:') {
            throw new \RuntimeException('Guest tests require isolated SQLite :memory:.');
        }
        // Add schema to this fresh in-memory connection; never reset or seed a database.
        Artisan::call('migrate', ['--database' => 'sqlite']);
        config(['services.openai.api_key' => 'test-key']);
        Http::preventStrayRequests();
        User::factory()->create(['username' => 'ai_dispatcher']);
    }

    private function guest(string $mode = 'cbm'): array
    {
        return $this->postJson('/api/lena-guest/session', ['code' => '2580', 'lang' => 'bs', 'mode' => $mode])
            ->assertCreated()->json('data');
    }

    public function test_code_is_required_and_each_session_is_isolated(): void
    {
        $this->postJson('/api/lena-guest/session', ['code' => '0000', 'lang' => 'bs', 'mode' => 'cbm'])->assertUnprocessable();
        $first = $this->guest();
        $second = $this->guest();
        $this->assertNotSame($first['user_id'], $second['user_id']);
        $this->withToken($first['token'])->getJson('/api/lena-guest/current')->assertOk()->assertJsonPath('data.id', $first['conversation_id']);
        $this->withToken($first['token'])->postJson('/api/lena-realtime/transcript', [
            'conversation_id' => $second['conversation_id'], 'speaker' => 'caller', 'text' => 'Private message',
        ])->assertForbidden();
        foreach (['users', 'loads', 'conversations', 'lena-guest/conversations', 'auth/me'] as $path) {
            $this->withToken($first['token'])->getJson('/api/'.$path)->assertForbidden();
        }
        $this->withToken($first['token'])->postJson('/api/loads', [])->assertForbidden();
    }

    public function test_spoken_turns_are_saved_and_only_admin_can_review_them(): void
    {
        $guest = $this->guest();
        foreach (['caller' => 'Imam 17 kutija.', 'lena' => 'Koja je dužina jedne kutije?'] as $speaker => $text) {
            $this->withToken($guest['token'])->postJson('/api/lena-realtime/transcript', [
                'conversation_id' => $guest['conversation_id'], 'speaker' => $speaker, 'text' => $text,
            ])->assertOk()->assertJsonPath('data.saved', true);
        }
        $ordinary = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($ordinary->createToken('test')->plainTextToken)->getJson('/api/lena-guest/conversations')->assertForbidden();
        $role = Role::query()->create(['name' => 'superadmin', 'label' => 'Superadmin']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($admin->createToken('test')->plainTextToken)
            ->getJson('/api/lena-guest/conversations/'.$guest['conversation_id'])->assertOk()
            ->assertJsonFragment(['body' => 'Imam 17 kutija.'])->assertJsonFragment(['body' => 'Koja je dužina jedne kutije?']);
    }

    public function test_guest_conversations_appear_in_normal_messages_only_for_guest_managers(): void
    {
        $guest = $this->guest();
        $privateUser = User::factory()->create();
        $private = Conversation::query()->create(['created_by_user_id' => $privateUser->id, 'channel' => 'inapp', 'subject' => 'Private chat']);

        foreach (['superadmin', 'master', 'user'] as $name) {
            $role = Role::query()->firstOrCreate(['name' => $name], ['label' => $name]);
            $user = User::factory()->create(['role_id' => $role->id]);
            $this->app['auth']->forgetGuards();
            $this->withToken($user->createToken('test')->plainTextToken);
            $list = $this->getJson('/api/conversations')->assertOk();
            if ($name === 'user') {
                $list->assertJsonMissing(['id' => $guest['conversation_id'], 'subject' => 'AI Dispatch — LIVE CALL CBM']);
                $this->getJson('/api/conversations/'.$guest['conversation_id'])->assertNotFound();
                $this->getJson('/api/messages?conversation_id='.$guest['conversation_id'])->assertOk()->assertJsonCount(0, 'data');
            } else {
                $list->assertJsonFragment(['id' => $guest['conversation_id'], 'subject' => 'AI Dispatch — LIVE CALL CBM']);
                $this->getJson('/api/conversations/'.$guest['conversation_id'])->assertOk();
                $this->getJson('/api/messages?conversation_id='.$guest['conversation_id'])->assertOk()->assertJsonFragment(['body' => '[[LENA_ACTION:freeroam]]']);
            }
            $this->getJson('/api/conversations/'.$private->id)->assertNotFound();
        }
    }

    public function test_cbm_call_opens_with_question_and_existing_skill(): void
    {
        Http::fake(['*' => Http::response(['value' => 'ephemeral-test-secret'])]);
        $guest = $this->guest();
        $response = $this->withToken($guest['token'])->postJson('/api/lena-realtime/session', [
            'lang' => 'bs', 'conversation_id' => $guest['conversation_id'],
        ])->assertOk();
        $this->assertStringContainsString('Koliko paketa imate?', $response->json('data.opening_prompt'));
        Http::assertSent(fn ($request) => str_contains($request['session']['instructions'], 'Guided live demonstration'));
    }

    public function test_expired_token_cannot_start_calls(): void
    {
        $guest = $this->guest();
        $this->travel(61)->minutes();
        $this->withToken($guest['token'])->getJson('/api/lena-guest/current')->assertUnauthorized();
    }

    public function test_publishing_requires_review_and_is_idempotent(): void
    {
        $guest = $this->guest();
        $draft = LoadDraft::query()->create(['title' => 'CBM guest load', 'transport_type' => 'road', 'cargo_type' => 'FTL',
            'weight_kg' => 100, 'volume_m3' => 1.6065, 'pickup_city' => 'Sarajevo', 'pickup_country_code' => 'BA',
            'delivery_city' => 'Zagreb', 'delivery_country_code' => 'HR',
            'contact' => ['supplier' => ['name' => 'Demo supplier', 'email' => 'supplier@example.test', 'phone' => '+38761123456']]]);
        Conversation::findOrFail($guest['conversation_id'])->update(['load_draft_id' => $draft->id]);
        $this->withToken($guest['token'])->postJson('/api/lena-guest/publish', [])->assertUnprocessable();
        $this->withToken($guest['token'])->postJson('/api/lena-guest/publish', ['confirmed' => true, 'draft_updated_at' => 'old'])->assertStatus(409);
        $body = ['confirmed' => true, 'draft_updated_at' => $draft->updated_at->toISOString()];
        $load = $this->withToken($guest['token'])->postJson('/api/lena-guest/publish', $body)->assertCreated()
            ->assertJsonPath('data.status', 'posted')->assertJsonPath('data.customer_user_id', $guest['user_id'])->json('data');
        $this->withToken($guest['token'])->postJson('/api/lena-guest/publish', $body)->assertOk()->assertJsonPath('data.id', $load['id']);
        $this->assertDatabaseCount('loads', 1);
    }
}
