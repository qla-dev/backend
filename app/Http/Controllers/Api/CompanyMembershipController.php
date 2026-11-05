<?php

namespace App\Http\Controllers\Api;

use App\Models\CompanyMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyMembershipController extends CrudController
{
    protected function applyFilters(Builder $query, Request $request): void
    {
        if (! $request->user()->isSuperAdminOrMaster()) {
            $query->whereIn('company_id', app(\App\Services\CompanyTeamAccess::class)->companies($request->user())->select('companies.id'));
        }
        if ($request->filled('company_id')) $query->where('company_id', $request->integer('company_id'));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $member = CompanyMembership::findOrFail($id);
        app(\App\Services\CompanyTeamAccess::class)->authorize($request->user(), (int) $member->company_id);
        return parent::show($request, $id);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(\Illuminate\Support\Facades\Schema::hasColumn('company_user','company_role_id'),503,'Company team migration required.');
        $data = $request->validate(['company_id'=>['required','integer','exists:companies,id'],'user_id'=>['required','integer','exists:users,id']]);
        app(\App\Services\CompanyTeamAccess::class)->authorize($request->user(), (int)$data['company_id'], true);
        abort_if(CompanyMembership::where('company_id',$data['company_id'])->where('user_id',$data['user_id'])->exists(),422,'Membership already exists.');
        abort_if((int)\App\Models\Company::findOrFail($data['company_id'])->owner_user_id === (int)$data['user_id'],422,'The owner membership is managed by company ownership.');
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request,$data) {
            \Illuminate\Support\Facades\DB::table('companies')->where('id',$data['company_id'])->lockForUpdate()->first();
            abort_if(CompanyMembership::where('company_id',$data['company_id'])->where('user_id',$data['user_id'])->exists(),422,'Membership already exists.');
            $member = CompanyMembership::create([...$data,'status'=>'inactive','invited_by_user_id'=>$request->user()->id,'joined_at'=>now()]);
            $request->merge(['status'=>$request->input('status','active')]);
            return $this->update($request, $member->id);
        });
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->merge(['status'=>'inactive']);
        return $this->update($request,$id);
    }

    protected function applyOrdering(Builder $query, Request $request): void
    {
        $query->orderByRaw('CASE WHEN user_id = (SELECT owner_user_id FROM companies WHERE companies.id = company_user.company_id) THEN 0 ELSE 1 END')
            ->orderByDesc('id');
    }

    protected function modelClass(): string
    {
        return CompanyMembership::class;
    }

    protected function relations(): array
    {
        return ['company', 'user.role', 'inviter'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['company_id' => [$p, 'integer', 'exists:companies,id'], 'user_id' => [$p, 'integer', 'exists:users,id'], 'invited_by_user_id' => ['nullable', 'integer', 'exists:users,id'], 'status' => ['sometimes', 'string', 'max:50'], 'joined_at' => ['nullable', 'date']];
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $membership = CompanyMembership::query()->with(['user', 'company'])->findOrFail($id);
        abort_unless(app(\App\Services\Ops\OpsDepartments::class)->manages($request->user(),(int)$membership->company_id), 403);
        abort_if(($request->has('role_id')||$request->has('status')) && (int) $membership->company->owner_user_id === (int) $membership->user_id, 422, 'The company owner role cannot be changed.');

        abort_unless(\Illuminate\Support\Facades\Schema::hasColumn('company_user','company_role_id'),503,'Company team migration required.');
        $data = $request->validate([
            'role_id' => ['sometimes', 'integer', Rule::exists('roles', 'id')->where(
                fn ($query) => $query->whereIn('name', \App\Services\CompanyTeamAccess::ROLES)->where('is_active',true)
            )],
            'status' => ['sometimes', Rule::in(['active','inactive'])],
            'business_departments'=>['sometimes','array','max:7'],'business_departments.*'=>['string','distinct',Rule::in(\App\Services\CompanyTeamAccess::DEPARTMENTS)],
            'subdepartments'=>['sometimes','array','max:5'],'subdepartments.*'=>['string','distinct',Rule::in(\App\Services\CompanyTeamAccess::SUBDEPARTMENTS)],
            'departments'=>['sometimes','array','max:13'],'departments.*'=>['string','distinct',Rule::in(\App\Services\Ops\OpsDepartments::ALL)],
        ]);

        abort_if($request->hasAny(['departments','business_departments','subdepartments'])&&$membership->status!=='active'&&($data['status']??'')!=='active',422,'Departments require an active company member.');
        $before=$membership->only(['company_role_id','departments','business_departments','subdepartments','status']);
        if ($request->hasAny(['departments','business_departments','subdepartments'])) {
            $old=app(\App\Services\CompanyTeamAccess::class)->organization(isset($data['departments'])?(object)['departments'=>$data['departments']]:$membership);
            $business=$data['business_departments']??$old['business_departments'];
            $sub=$data['subdepartments']??$old['subdepartments'];
            abort_if($sub&&!in_array('operations',$business,true),422,'Transport subdepartments belong to Operations. Finance does not require one.');
            $data['business_departments']=$business;$data['subdepartments']=$sub;
            $data['departments']=$request->has('departments') && !$request->hasAny(['business_departments','subdepartments'])
                ? $data['departments'] : array_values(array_unique([...$sub,...array_intersect($business,['customs','warehouse','finance'])]));
        }
        if (isset($data['role_id'])) {
            $data['company_role_id'] = $data['role_id'];
            unset($data['role_id']);
        }
        if ($data !== []) {
            $membership->update($data);
        }

        if(\Illuminate\Support\Facades\Schema::hasTable('compliance_audit'))app(\App\Services\OperationalCompliance::class)->audit((int)$membership->company_id,'company_member',(int)$membership->id,(int)$request->user()->id,'membership_updated',$before,$data);
        return $this->success(
            (new \App\Http\Resources\EntityResource($membership->load($this->relations())))->resolve($request),
            'Team member updated successfully.'
        );
    }
}
