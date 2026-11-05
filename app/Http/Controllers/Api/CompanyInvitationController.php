<?php

namespace App\Http\Controllers\Api;

use App\Models\CompanyInvitation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Services\CompanyTeamAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CompanyInvitationController extends CrudController
{
    protected function applyFilters(Builder $query, Request $request): void
    {
        $team=app(CompanyTeamAccess::class);
        $ids=$team->companies($request->user())->get(['id'])->filter(fn($c)=>$team->manages($request->user(),$c->id))->pluck('id');
        $query->whereIn('company_id', $ids);
        if ($request->filled('company_id')) $query->where('company_id', $request->integer('company_id'));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $invite = CompanyInvitation::findOrFail($id);
        app(CompanyTeamAccess::class)->authorize($request->user(), (int)$invite->company_id, true);
        return parent::show($request, $id);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $invite = CompanyInvitation::findOrFail($id);
        app(CompanyTeamAccess::class)->authorize($request->user(), (int)$invite->company_id, true);
        abort_unless($invite->status === 'pending',422,'Only pending invitations can be changed.');
        $data=$request->validate(['role_id'=>['sometimes','integer',Rule::exists('roles','id')->where(fn($q)=>$q->whereIn('name',CompanyTeamAccess::ROLES)->where('is_active',true))],
            'status'=>['sometimes',Rule::in(['pending','cancelled'])]]);
        $invite->update($data);
        return $this->success((new \App\Http\Resources\EntityResource($invite->load($this->relations())))->resolve($request),'Invitation updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->merge(['status'=>'cancelled']);
        return $this->update($request,$id);
    }

    public function accept(Request $request): JsonResponse
    {
        $data=$request->validate(['token'=>['required','string','size:64']]);
        abort_unless(Schema::hasColumn('company_user','company_role_id'),503,'Company team migration required.');
        return DB::transaction(function () use ($request,$data) {
            $invite=CompanyInvitation::where('token',$data['token'])->lockForUpdate()->firstOrFail();
            abort_unless(strcasecmp($invite->email,$request->user()->email)===0,403);
            if($invite->status==='accepted' && (int)$invite->accepted_by_user_id===(int)$request->user()->id) return $this->success(null,'Invitation already accepted.');
            abort_unless($invite->status==='pending' && $invite->expires_at && $invite->expires_at->isFuture(),422,'Invitation expired or inactive.');
            abort_unless(in_array($invite->role?->name,CompanyTeamAccess::ROLES,true) && $invite->role->is_active,422,'Invitation role unavailable.');
            abort_unless($invite->inviter && app(CompanyTeamAccess::class)->manages($invite->inviter,(int)$invite->company_id),422,'Inviter no longer manages this company.');
            DB::table('companies')->where('id',$invite->company_id)->lockForUpdate()->first();
            $existing=DB::table('company_user')->where('company_id',$invite->company_id)->where('user_id',$request->user()->id)->first();
            abort_if($existing && $existing->status==='active',422,'An active membership already exists.');
            DB::table('company_user')->updateOrInsert(['company_id'=>$invite->company_id,'user_id'=>$request->user()->id],
                ['company_role_id'=>$invite->role_id,'status'=>'active','invited_by_user_id'=>$invite->invited_by_user_id,'joined_at'=>now(),'updated_at'=>now()]);
            $invite->update(['status'=>'accepted','accepted_at'=>now(),'accepted_by_user_id'=>$request->user()->id]);
            return $this->success(['company_id'=>$invite->company_id],'Invitation accepted.');
        });
    }

    public function availableUsers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'between:1,25'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        app(CompanyTeamAccess::class)->authorize($request->user(), $data['company_id'], true);

        $users = User::query()
            ->with('role:id,name,label')
            ->where('is_active', true)
            ->whereDoesntHave('companies', fn($q)=>$q->where('companies.id',$data['company_id'])->where('company_user.status','active'))
            ->whereNotIn('email', CompanyInvitation::query()->where('company_id',$data['company_id'])->where('status', 'pending')->select('email'))
            ->whereHas('role', fn ($query) => $query->whereNotIn('name', [...Role::PROTECTED_NAMES, 'system', 'guest']))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($scope) use ($search): void {
                    $scope->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->limit((int) ($data['limit'] ?? 10))
            ->get(['id', 'role_id', 'name', 'email', 'username']);

        return response()->json(['message' => 'Available users retrieved.', 'data' => $users, 'meta' => [], 'errors' => []]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->integer('company_id');
        app(CompanyTeamAccess::class)->authorize($request->user(), $companyId, true);
        $request->merge(['invited_by_user_id' => $request->user()->id,'accepted_by_user_id'=>null,'accepted_at'=>null,
            'token'=>Str::random(64),'status'=>'pending','expires_at'=>now()->addDays(7)->toIso8601String()]);

        return parent::store($request);
    }

    protected function modelClass(): string
    {
        return CompanyInvitation::class;
    }

    protected function relations(): array
    {
        return ['company', 'role', 'inviter', 'acceptedBy'];
    }

    protected function searchColumns(): array
    {
        return ['email', 'status'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['company_id' => [$p, 'integer', 'exists:companies,id'], 'role_id' => [$p, 'integer', Rule::exists('roles', 'id')->where(fn ($query) => $query->whereIn('name', CompanyTeamAccess::ROLES)->where('is_active',true))], 'invited_by_user_id' => [$p, 'integer', 'exists:users,id'], 'accepted_by_user_id' => ['nullable', 'integer', 'exists:users,id'], 'email' => [$p, 'email'], 'token' => [$p, 'string', 'size:64'], 'status' => ['sometimes', 'string', 'max:50'], 'expires_at' => [$p, 'date'], 'accepted_at' => ['nullable', 'date']];
    }
}
