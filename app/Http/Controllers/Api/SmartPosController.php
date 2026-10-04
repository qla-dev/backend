<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Accounting\AccountingAccess;
use App\Services\Accounting\PantheonConnector;
use App\Services\Fiscal\SmartPos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class SmartPosController extends Controller
{
    public function __construct(private AccountingAccess $access, private SmartPos $pos, private PantheonConnector $pantheon) {}

    private function company(Request $r, string $ability): int
    {
        $id = (int) $r->route('company');
        $this->access->authorize($r->user(), $id, $ability);

        return $id;
    }

    private function response(mixed $data): JsonResponse
    {
        return response()->json(['data' => $data, 'message' => 'Smart POS operation completed.', 'errors' => [], 'meta' => []]);
    }

    public function status(Request $r): JsonResponse
    {
        $this->company($r, 'pos');

        return $this->response($this->pos->status());
    }

    public function operations(Request $r): JsonResponse
    {
        $id = $this->company($r, 'pos');

        return $this->response(DB::table('accounting_fiscal_operations')->leftJoin('users', 'users.id', '=', 'accounting_fiscal_operations.requested_by')
            ->where('accounting_fiscal_operations.company_id', $id)->orderByDesc('accounting_fiscal_operations.id')->limit(100)
            ->get(['accounting_fiscal_operations.id', 'invoice_id', 'operation', 'accounting_fiscal_operations.status', 'command', 'error', 'accounting_fiscal_operations.created_at', 'users.name as user_name']));
    }

    public function duplicateOptions(Request $r): JsonResponse
    {
        $id = $this->company($r, 'pos');

        return $this->response(Invoice::where('company_id', $id)->where('accounting_managed', true)->whereIn('fiscal_status', ['fiscalised', 'refunded'])
            ->where('fiscal_number', '>', 0)->orderBy('fiscal_number')->get(['id', 'number', 'fiscal_number', 'fiscalised_at', 'partner_name', 'total', 'currency']));
    }

    public function fiscalise(Request $r): JsonResponse
    {
        $id = $this->company($r, 'pos');
        $data = $r->validate(['payment_method' => ['required', Rule::in(config('fiscal.payment_methods'))], 'request_key' => ['required', 'string', 'max:100']]);

        return $this->response($this->pos->fiscalise($id, $r->user()->id, (int) $r->route('invoice'), $data['payment_method'], $data['request_key']));
    }

    public function refund(Request $r): JsonResponse
    {
        $id = $this->company($r, 'pos');
        $data = $r->validate(['reason' => ['required', 'string', 'max:500'], 'request_key' => ['required', 'string', 'max:100']]);

        return $this->response($this->pos->refund($id, $r->user()->id, (int) $r->route('invoice'), $data['reason'], $data['request_key']));
    }

    public function confirm(Request $r): JsonResponse
    {
        $id = $this->company($r, 'pos');
        $data = $r->validate(['fiscal_number' => ['required', 'integer', 'min:1']]);

        return $this->response($this->pos->confirm($id, $r->user()->id, (int) $r->route('invoice'), (int) $data['fiscal_number']));
    }

    public function report(Request $r): JsonResponse
    {
        $id = $this->company($r, 'pos');
        $type = (string) $r->route('type');
        $data = $r->validate(['request_key' => ['required', 'string', 'max:100'], 'preview' => ['sometimes', 'boolean'],
            'from' => [Rule::requiredIf($type === 'periodic'), 'nullable', 'date'], 'to' => [Rule::requiredIf($type === 'periodic'), 'nullable', 'date', 'after_or_equal:from'],
            'invoice_id' => [Rule::requiredIf($type === 'duplicate'), 'nullable', 'integer'],
            'lines' => [Rule::requiredIf($type === 'text'), 'array', 'max:40'], 'lines.*' => ['string', 'max:42']]);

        return $this->response($this->pos->report($id, $r->user()->id, $type, $data));
    }

    public function summary(Request $r): JsonResponse
    {
        $id = $this->company($r, 'pos');
        $data = $r->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return $this->response($this->pos->summary($id, $data['from'], $data['to']));
    }

    public function pantheon(Request $r): JsonResponse
    {
        return $this->response($this->pantheon->settings($this->company($r, 'integrations')));
    }

    public function savePantheon(Request $r): JsonResponse
    {
        $id = $this->company($r, 'integrations');
        $docType = ['required', 'regex:/^[0-9A-Z]{4}$/'];
        $data = $r->validate(['host' => ['required', 'string', 'max:255'], 'port' => ['required', 'integer', 'between:1,65535'], 'database' => ['required', 'string', 'max:128'],
            'schema' => ['required', 'regex:/^[A-Za-z_][A-Za-z0-9_]{0,63}$/'], 'username' => ['required', 'string', 'max:128'], 'password' => ['nullable', 'string', 'max:255'],
            'allow_write' => ['required', 'boolean'], 'clerk_id' => ['required', 'integer', 'min:0'],
            'outgoing_doc_type' => $docType, 'incoming_doc_type' => $docType, 'journal_doc_type' => $docType,
            'sync_enabled' => ['required', 'boolean'], 'sync_interval_minutes' => ['required', 'integer', 'between:5,1440'], 'push_entries_from' => ['nullable', 'date'],
            'default_country_code' => ['nullable', 'regex:/^[A-Z]{2}$/'], 'account_kinds' => ['nullable', 'array'],
            'account_kinds.*' => [Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'sales_doc_types' => ['sometimes', 'regex:/^[0-9A-Z]{4}(,[0-9A-Z]{4}){0,20}$/'], 'delivery_doc_types' => ['sometimes', 'regex:/^[0-9A-Z]{4}(,[0-9A-Z]{4}){0,20}$/'],
            'crm_push_enabled' => ['sometimes', 'boolean'], 'crm_push_doc_type' => ['sometimes', 'nullable', 'regex:/^[0-9A-Z]{4}$/'], 'crm_push_from' => ['sometimes', 'nullable', 'date']]);
        // Choosing account kinds or enabling sync changes the local chart of accounts.
        $this->access->authorize($r->user(), $id, 'setup');

        return $this->response($this->pantheon->save($id, $r->user()->id, $data));
    }

    public function testPantheon(Request $r): JsonResponse
    {
        return $this->response($this->pantheon->test($this->company($r, 'integrations')));
    }

    public function previewPantheon(Request $r): JsonResponse
    {
        $id = $this->company($r, 'integrations');
        $data = $r->validate(['type' => ['required', Rule::in(['accounts', 'partners', 'taxes'])]]);

        return $this->response($this->pantheon->preview($id, $data['type']));
    }

    public function syncPantheon(Request $r): JsonResponse
    {
        $id = $this->company($r, 'integrations');
        $this->access->authorize($r->user(), $id, 'setup');

        return $this->response($this->pantheon->sync($id, $r->user()->id));
    }

    public function exportPantheon(Request $r): JsonResponse
    {
        $id = $this->company($r, 'integrations');
        $data = $r->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'write' => ['required', 'boolean']]);
        if ($data['write']) {
            $this->access->authorize($r->user(), $id, 'post');
        }

        return $this->response($this->pantheon->export($id, $r->user()->id, $data['from'], $data['to'], $data['write']));
    }
}
