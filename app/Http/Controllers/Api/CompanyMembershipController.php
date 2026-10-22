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
            $query->whereIn('company_id', $request->user()->companies()->select('companies.id'));
        }
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

        $data = $request->validate([
            'role_id' => ['sometimes', 'integer', Rule::exists('roles', 'id')->where(
                fn ($query) => $query->whereIn('name', ['manager', 'dispatcher', 'customs_officer', 'finance', 'driver'])
            )],
            'status' => ['sometimes', Rule::in(['active','inactive'])],
            'departments'=>['sometimes','array','max:9'],'departments.*'=>['string','distinct',Rule::in(\App\Services\Ops\OpsDepartments::ALL)],
        ]);

        abort_if(isset($data['departments'])&&$membership->status!=='active',422,'Departments require an active company member.');
        $before=$membership->only(['departments','status']);
        if (isset($data['role_id'])) {
            $membership->user->update(['role_id' => $data['role_id']]);
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
