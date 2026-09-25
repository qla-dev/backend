<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use App\Services\LenaGuest;
use App\Services\LenaRealtimeSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LenaGuestController extends Controller
{
    public function store(Request $request, LenaRealtimeSession $realtime)
    {
        $data = $request->validate(['code' => ['required', 'string'], 'lang' => ['required', 'in:en,de,bs,hr,sr'], 'mode' => ['required', 'in:cbm,free']]);
        if (! hash_equals('2580', $data['code'])) throw ValidationException::withMessages(['code' => ['Invalid access code.']]);
        abort_unless($realtime->isConfigured(), 503, 'Calling Lena is not set up on this server yet.');
        $dispatcher = User::query()->where('username', 'ai_dispatcher')->firstOrFail();
        $role = Role::query()->where('name', 'user')->firstOrFail();
        return DB::transaction(function () use ($data, $dispatcher, $role) {
            $id = (string) Str::uuid();
            $user = User::query()->create(['role_id' => $role->id, 'name' => 'Lena guest', 'username' => 'lena_guest_'.$id,
                'email' => $id.'@lena-guest.invalid', 'password' => Str::random(64), 'language' => $data['lang'], 'is_active' => true]);
            $conversation = Conversation::query()->create(['created_by_user_id' => $user->id, 'channel' => 'inapp',
                'subject' => 'AI Dispatch — LIVE CALL '.strtoupper($data['mode']), 'last_message_at' => now()]);
            $conversation->participants()->attach([$user->id, $dispatcher->id]);
            $conversation->messages()->create(['sender_user_id' => $user->id, 'body' => '[[LENA_ACTION:freeroam]]', 'sent_at' => now()]);
            $token = $user->createToken(LenaGuest::TOKEN, ['demo:'.$data['mode']], now()->addHour());
            return response()->json(['data' => ['token' => $token->plainTextToken, 'user_id' => $user->id, 'conversation_id' => $conversation->id]], 201);
        });
    }

    private function conversation(Request $request): Conversation
    {
        abort_unless(LenaGuest::active($request->user()), 403);
        return Conversation::query()->where('created_by_user_id', $request->user()->id)->firstOrFail();
    }

    public function current(Request $request)
    {
        return response()->json(['data' => $this->conversation($request)->load(['freightLoadDraft', 'freightLoad.stops', 'messages.sender:id,username'])]);
    }

    public function publish(Request $request, LoadController $loads)
    {
        $request->validate(['confirmed' => ['required', 'accepted'], 'draft_updated_at' => ['required', 'string']]);
        $id = $this->conversation($request)->id;
        return DB::transaction(function () use ($request, $loads, $id) {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($id);
            if ($conversation->load_id) return response()->json(['data' => $conversation->freightLoad]);
            $draft = $conversation->freightLoadDraft()->lockForUpdate()->first();
            abort_unless($draft, 422, 'No load draft yet.');
            abort_unless($draft->updated_at->toISOString() === $request->input('draft_updated_at'), 409, 'The draft changed. Review it again before publishing.');
            $data = array_filter($draft->toArray(), fn ($value) => $value !== null);
            foreach (['id', 'company_id', 'consignee_customer_id', 'assigned_driver_user_id', 'vehicle_id', 'warehouse_id', 'created_at', 'updated_at'] as $key) unset($data[$key]);
            $data['customer_user_id'] = $request->user()->id;
            $data['status'] = 'posted';
            $data['published_at'] = now()->toIso8601String();
            $data['stops'] = [];
            foreach (['pickup', 'delivery'] as $index => $side) {
                $data['stops'][] = ['type' => $side, 'position' => $index + 1,
                    'city' => $draft->{$side.'_city'}, 'country_code' => $draft->{$side.'_country_code'},
                    'address' => $draft->{$side.'_address'}, 'window_starts_at' => $draft->{$side.'_date'}?->toDateString()];
            }
            foreach ($draft->extra_stops ?? [] as $stop) {
                $data['stops'][] = ['type' => $stop['side'], 'position' => count($data['stops']) + 1,
                    'city' => $stop['city'] ?? null, 'country_code' => $stop['country'] ?? null,
                    'address' => $stop['address'] ?? null, 'window_starts_at' => $stop['date'] ?? null];
            }
            $publish = Request::create('/api/loads', 'POST', $data);
            $publish->setUserResolver(fn () => $request->user());
            $result = $loads->store($publish);
            $conversation->update(['load_id' => $result->getData(true)['data']['id']]);
            return $result;
        });
    }

    private function guestConversations()
    {
        return Conversation::query()->whereHas('creator', fn ($query) => $query->where('email', 'like', '%@lena-guest.invalid'));
    }

    public function index()
    {
        return response()->json($this->guestConversations()->withCount('messages')->latest('id')->paginate(20));
    }

    public function show(int $conversation)
    {
        return response()->json(['data' => $this->guestConversations()->with(['messages.sender:id,name,username', 'freightLoadDraft', 'freightLoad.stops'])->findOrFail($conversation)]);
    }
}
