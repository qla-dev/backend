<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingAccess;
use App\Services\OperationalCompliance;
use App\Services\Ops\TransportFlow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class TransportFlowController extends Controller
{
    private function order(Request $r): object
    {
        $company = (int) $r->route('company');
        app(AccountingAccess::class)->authorize($r->user(), $company, 'ops');
        return app(OperationalCompliance::class)->order($company, (int) $r->route('order'));
    }

    public function save(Request $r)
    {
        $order = $this->order($r);
        $data = $r->validate(['checklist_revision'=>['required','integer','min:0'],'revision'=>['required','integer','min:0'], 'transport_type'=>['required',Rule::in(TransportFlow::MODES)],
            'variant'=>['nullable',Rule::in(['FTL','LTL','FCL','LCL'])], 'legs'=>['present','array','max:30'],
            'legs.*'=>['array:key,mode,variant,from,to,status,responsible_user_id,due_date,document_ids'],
            'legs.*.key'=>['required','regex:/^[a-zA-Z0-9_-]+$/','max:60','distinct'], 'legs.*.mode'=>['required',Rule::in(['road','sea','air','rail','warehouse','customs'])],
            'legs.*.variant'=>['nullable',Rule::in(['FTL','LTL','FCL','LCL'])], 'legs.*.from'=>['required','string','max:200'], 'legs.*.to'=>['required','string','max:200'],
            'legs.*.status'=>['required',Rule::in(['planned','in_progress','delivered'])], 'legs.*.responsible_user_id'=>['nullable','integer'],
            'legs.*.due_date'=>['nullable','date'], 'legs.*.document_ids'=>['sometimes','array','max:30'], 'legs.*.document_ids.*'=>['integer','distinct'],
            'transit'=>['present','array','max:30'], 'transit.*'=>['array:key,leg_key,lrn,mrn,status,responsible_user_id,due_date,next_action,document_ids'],
            'transit.*.key'=>['required','string','max:60','distinct'], 'transit.*.leg_key'=>['nullable','string','max:60'],
            'transit.*.lrn'=>['nullable','string','max:100'], 'transit.*.mrn'=>['nullable','string','max:100'],
            'transit.*.status'=>['required',Rule::in(['draft','prepared','received','released','discharged'])],
            'transit.*.responsible_user_id'=>['nullable','integer'], 'transit.*.due_date'=>['nullable','date'], 'transit.*.next_action'=>['nullable','string','max:1000'],
            'transit.*.document_ids'=>['present','array','max:30'], 'transit.*.document_ids.*'=>['integer','distinct']]);
        foreach ($data['transit'] as $item) abort_if(!empty($item['leg_key']) && !in_array($item['leg_key'], array_column($data['legs'],'key')), 422, 'Nepoznata dionica tranzita.');
        return response()->json(['data'=>app(TransportFlow::class)->save($order, $r->user()->id, $data)]);
    }

}
