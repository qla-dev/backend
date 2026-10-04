<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingAccess;
use App\Services\Crm\CrmPantheonPush;
use App\Services\Crm\CrmPipeline;
use App\Services\Crm\PantheonCrmSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class CrmController extends Controller
{
    public function __construct(private AccountingAccess $access, private CrmPipeline $crm, private PantheonCrmSync $sync) {}

    private function company(Request $r): int
    {
        $id = (int) $r->route('company');
        $this->access->authorize($r->user(), $id, 'crm');

        return $id;
    }

    private function response(mixed $data): JsonResponse
    {
        return response()->json(['data' => $data, 'message' => 'CRM operation completed.', 'errors' => [], 'meta' => []]);
    }

    public function overview(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $filters = $r->validate(['stage' => ['nullable', Rule::in(PantheonCrmSync::STAGES)], 'partner_id' => ['nullable', 'integer'], 'customer_key' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'search' => ['nullable', 'string', 'max:100'], 'limit' => ['nullable', 'integer', 'min:1', 'max:500']]);
        $connector = DB::table('accounting_pantheon_connectors')->where('company_id', $id)->first(['sales_doc_types', 'delivery_doc_types', 'last_crm_sync_at']);

        return $this->response(['documents' => $this->crm->documents($id, $filters), 'follow_ups' => $this->crm->dueFollowUps($id),
            'partners' => DB::table('accounting_partners')->where('company_id', $id)->orderBy('name')->get(['id', 'name', 'tax_number']),
            'members' => DB::table('users')->whereIn('id', DB::table('company_user')->where('company_id', $id)->where('status', 'active')->pluck('user_id')
                ->push(DB::table('companies')->where('id', $id)->value('owner_user_id')))->get(['id', 'name']),
            'pantheon' => $connector, 'stage_counts' => DB::table('crm_documents')->where('company_id', $id)->selectRaw('stage, COUNT(*) as documents')->groupBy('stage')->pluck('documents', 'stage')]);
    }

    public function show(Request $r): JsonResponse
    {
        return $this->response($this->crm->show($this->company($r), (int) $r->route('document')));
    }

    public function store(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $data = $r->validate(['stage' => ['required', Rule::in(['lead', 'offer'])], 'partner_id' => ['nullable', 'integer'], 'customer_name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'], 'title' => ['required', 'string', 'max:255'], 'issued_on' => ['nullable', 'date'], 'valid_until' => ['nullable', 'date'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'], 'total_amount' => ['nullable', 'numeric', 'min:0'], 'note' => ['nullable', 'string', 'max:5000'],
            'owner_user_id' => ['nullable', 'integer'], 'next_follow_up_on' => ['nullable', 'date'], 'items' => ['sometimes', 'array', 'max:200'], 'items.*.item_code' => ['nullable', 'string', 'max:30'], 'items.*.name' => ['required', 'string', 'max:255'], 'items.*.quantity' => ['required', 'numeric', 'min:0'], 'items.*.unit' => ['nullable', 'string', 'max:6'], 'items.*.unit_price' => ['required', 'numeric', 'min:0'], 'items.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'], 'items.*.vat_percent' => ['nullable', 'numeric', 'between:0,100'], 'items.*.vat_code' => ['nullable', 'string', 'max:2'], 'items.*.product_id' => ['nullable', 'integer']]);

        return $this->response($this->crm->create($id, $r->user()->id, $data));
    }

    public function update(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $data = $r->validate(['stage' => ['sometimes', Rule::in(PantheonCrmSync::STAGES)], 'owner_user_id' => ['sometimes', 'nullable', 'integer'],
            'next_follow_up_on' => ['sometimes', 'nullable', 'date'], 'lost_reason' => ['sometimes', 'nullable', 'string', 'max:500'], 'title' => ['sometimes', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'], 'note' => ['sometimes', 'nullable', 'string', 'max:5000'], 'valid_until' => ['sometimes', 'nullable', 'date'],
            'total_amount' => ['sometimes', 'numeric', 'min:0'], 'items' => ['sometimes', 'array', 'max:200'], 'items.*.item_code' => ['nullable', 'string', 'max:30'], 'items.*.name' => ['required', 'string', 'max:255'], 'items.*.quantity' => ['required', 'numeric', 'min:0'], 'items.*.unit' => ['nullable', 'string', 'max:6'], 'items.*.unit_price' => ['required', 'numeric', 'min:0'], 'items.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'], 'items.*.vat_percent' => ['nullable', 'numeric', 'between:0,100'], 'items.*.vat_code' => ['nullable', 'string', 'max:2'], 'items.*.product_id' => ['nullable', 'integer']]);

        return $this->response($this->crm->update($id, $r->user()->id, (int) $r->route('document'), $data));
    }

    public function followUp(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $data = $r->validate(['crm_document_id' => ['nullable', 'integer'], 'partner_id' => ['nullable', 'integer'], 'due_on' => ['required', 'date'],
            'note' => ['required', 'string', 'max:1000'], 'owner_user_id' => ['nullable', 'integer']]);

        return $this->response($this->crm->followUp($id, $r->user()->id, $data));
    }

    public function completeFollowUp(Request $r): JsonResponse
    {
        return $this->response($this->crm->completeFollowUp($this->company($r), (int) $r->route('followUp')));
    }

    public function report(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $data = $r->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return $this->response($this->crm->report($id, $data['from'], $data['to']));
    }

    /** SmartFreight offers/orders -> PANTHEON tHE_Order. write=false is a dry run listing what waits and why. */
    public function push(Request $r): JsonResponse
    {
        $id = (int) $r->route('company');
        $this->access->authorize($r->user(), $id, 'integrations');
        $data = $r->validate(['write' => ['required', 'boolean']]);

        return $this->response(app(CrmPantheonPush::class)->push($id, (bool) $data['write']));
    }

    public function pushSettings(Request $r): JsonResponse
    {
        $id = (int) $r->route('company');
        $this->access->authorize($r->user(), $id, 'integrations');
        if ($r->isMethod('post')) {
            $data = $r->validate(['crm_push_enabled' => ['required', 'boolean'], 'crm_push_doc_type' => ['nullable', 'regex:/^[0-9A-Z]{4}$/'], 'crm_push_from' => ['nullable', 'date']]);
            abort_unless(DB::table('accounting_pantheon_connectors')->where('company_id', $id)->exists(), 422, 'Connect PANTHEON first.');
            DB::table('accounting_pantheon_connectors')->where('company_id', $id)->update($data + ['updated_by' => $r->user()->id, 'updated_at' => now()]);
        }

        return $this->response(DB::table('accounting_pantheon_connectors')->where('company_id', $id)->first(['crm_push_enabled', 'crm_push_doc_type', 'crm_push_from', 'allow_write']));
    }

    public function sync(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $data = $r->validate(['full' => ['sometimes', 'boolean']]);

        return $this->response($this->sync->pull($id, $r->user()->id, (bool) ($data['full'] ?? false)));
    }
}
