<?php
namespace App\Services\Ops;
use App\Models\ShipmentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-through signals: the source record remains the evidence, no duplicate confirmations. */
final class OperationalSignals
{
    public function derive(object $order,array $items): array
    {
        if(!$order->load_id)return $items;
        $load=DB::table('loads')->find($order->load_id);$status=strtolower((string)($load->status??''));
        $movements=Schema::hasTable('warehouse_movements')?DB::table('warehouse_movements')->where('load_id',$order->load_id)->where('status','completed')->get():collect();
        return array_map(function($item)use($status,$movements,$order){
            $key=$item['key'];$movement=null;
            if(in_array($key,['record_storage_receipt','mode_receipt'],true))$movement=$movements->firstWhere('direction','inbound');
            if(in_array($key,['confirm_storage_dispatch','mode_dispatch'],true))$movement=$movements->firstWhere('direction','outbound');
            if($movement){$item['status']='completed';$item['system']=true;$item['confirmation_source']='warehouse_movement';$item['source_id']=$movement->id;$item['completed_at']=$movement->completed_at??$movement->updated_at??null;}
            if($key==='proof_of_delivery'&&in_array($status,['received','review','finished'],true)){
                // Delivery alone does not manufacture a signed POD. The existing POD task/document is authoritative.
                $pod=DB::table('ops_order_documents')->where('order_id',$order->id)->where('role','pod')->first();
                if($pod&&app(\App\Services\OperationalCompliance::class)->documentHashes([(int)$pod->document_id])){$item['status']='completed';$item['system']=true;$item['confirmation_source']='delivery_pod';$item['source_id']=$pod->document_id;}
            }
            return $item;
        },$items);
    }

    public function orderTimeline(object $order): array
    {
        if($order->workspace_id)return $this->timeline(ShipmentWorkspace::findOrFail($order->workspace_id),$order);
        $events=DB::table('ops_events')->where('order_id',$order->id)->get();$docs=app(OpsPortal::class)->documentState($order);
        $steps=[['key'=>'confirmed','label'=>'Order confirmed','complete'=>app(OpsOrders::class)->intake($order)['kind']==='order','source'=>'order_confirmation'],
            ['key'=>'documents','label'=>'Documents received and reviewed','complete'=>$docs['review_complete'],'source'=>'document_review'],
            ['key'=>'pickup','label'=>'Pickup confirmed','complete'=>$events->contains('event_type','loaded'),'source'=>'pickup_operation'],
            ['key'=>'delivered','label'=>'Delivered','complete'=>$events->contains('event_type','delivered'),'source'=>'delivery']];
        $review=app(OpsPortal::class)->context($order)['cargo_review']??[];
        $hold=in_array($review['status']??'',['pending_customer','rejected'],true)?'Customer confirmation of cargo data and price is required.':($docs['review_complete']?null:'Required documents must be supplied, received and reviewed.');
        return ['reference'=>$order->reference,'order_id'=>$order->id,'company_id'=>$order->company_id,'stages'=>$steps,'hold'=>$hold,
            'current'=>collect($steps)->filter(fn($x)=>$x['complete'])->last()['label']??'Order pending','next'=>collect($steps)->first(fn($x)=>!$x['complete'])['label']??'All operational milestones completed'];
    }

    public function timeline(ShipmentWorkspace $workspace,?object $order=null): array
    {
        $items=$order?app(\App\Services\OperationalCompliance::class)->read($order,false)['items']:$workspace->operational_checklist;
        $events=$order?DB::table('ops_events')->where('order_id',$order->id)->orderBy('occurred_at')->get():collect();
        $done=function(array $keys)use($items){return collect($items)->contains(fn($item)=>in_array($item['key'],$keys,true)&&($item['status']??'')==='completed'&&($item['applicable']??true));};
        $date=function(array $keys)use($items){return collect($items)->first(fn($item)=>in_array($item['key'],$keys,true)&&($item['status']??'')==='completed')['completed_at']??null;};
        $load=DB::table('loads')->find($workspace->load_id);$status=strtolower((string)($load->status??''));
        $receipt=$order&&collect($items)->contains('key','documents_received')?app(OpsPortal::class)->documentState($order):null;
        $stages=[['key'=>'booked','label'=>'Booking confirmed','complete'=>(bool)($workspace->accepted_offer_id||$workspace->booked_at),'at'=>$workspace->booked_at?->toIso8601String(),'source'=>'accepted_offer'],
            ['key'=>'documents','label'=>'Documents received and reviewed','complete'=>$receipt?$receipt['review_complete']:$done(['goods_documents','check_storage_documents','cargo_documents']),'at'=>$date(['documents_received','cargo_documents']),'source'=>'document_review'],
            ['key'=>'pickup','label'=>'Pickup confirmed','complete'=>$events->contains('event_type','loaded'),'at'=>$events->firstWhere('event_type','loaded')->occurred_at??null,'source'=>'pickup_operation'],
            ['key'=>'in_transit','label'=>'In transit','complete'=>in_array($status,['in_delivery','received','review','finished'],true)||$events->contains(fn($e)=>in_array($e->event_type,['loaded','border','delivered'],true)),'at'=>null,'source'=>'tracking']];
        if($order&&Schema::hasTable('warehouse_movements'))foreach(['inbound'=>['warehouse_received','Received at warehouse'],'outbound'=>['warehouse_dispatched','Dispatched from warehouse']] as $direction=>[$key,$label]){
            $movement=DB::table('warehouse_movements')->where('load_id',$workspace->load_id)->where('direction',$direction)->latest('id')->first();
            if($movement)$stages[]=['key'=>$key,'label'=>$label,'complete'=>$movement->status==='completed','at'=>$movement->completed_at??null,'source'=>'warehouse_movement','source_id'=>$movement->id];
        }
        if($order){$context=app(OpsPortal::class)->context($order);if(!empty($context['export_required'])||!empty($context['import_required'])||!empty($context['transit_required'])||!empty($load->customs_required)||!empty($load->requires_customs_bonded))$stages[]=['key'=>'customs','label'=>'Customs cleared','complete'=>$events->contains('event_type','customs_cleared')||$done(['customs_import','import_customs']),'at'=>$events->firstWhere('event_type','customs_cleared')->occurred_at??null,'source'=>'customs_operation'];}
        $stages[]=['key'=>'delivered','label'=>'Delivered','complete'=>in_array($status,['received','review','finished'],true)||$events->contains('event_type','delivered'),'at'=>$events->firstWhere('event_type','delivered')->occurred_at??null,'source'=>'delivery'];
        $stages[]=['key'=>'pod','label'=>'Proof of delivery available','complete'=>$done(['proof_of_delivery']),'at'=>$date(['proof_of_delivery']),'source'=>'pod'];
        $review=$order?app(CargoReview::class)->stored($order):[];
        $hold=in_array($review['status']??'',['pending_customer','rejected'],true)?'Customer confirmation of cargo data and price is required.':($receipt&&!$receipt['review_complete']?'Required documents must be supplied, received and reviewed.':null);
        $next=collect($stages)->first(fn($stage)=>!$stage['complete']);$last=collect($stages)->filter(fn($stage)=>$stage['complete'])->last();
        return ['reference'=>$order->reference??$workspace->reference,'order_id'=>$order->id??null,'company_id'=>$order->company_id??null,'workspace_id'=>$workspace->id,'load_id'=>$workspace->load_id,
            'current'=>$hold?'Action required':($last['label']??'Booking pending'),'next'=>$next['label']??'All operational milestones completed','hold'=>$hold,'stages'=>$stages];
    }
}
