<?php
namespace App\Services\Ops;

use App\Models\Load;
use App\Models\ShipmentWorkspace;
use App\Models\User;
use App\Services\Accounting\AccountingAccess;
use App\Services\Accounting\Decimal;
use App\Services\OperationalCompliance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Commercial amendment, never a posting, payment or invoice mutation. */
final class CargoReview
{
    public const FIELDS=['goods_type','cargo_type','weight_kg','volume_m3','pallets','quantity_measure','length_m','width_m','height_m'];

    public function stored(object $order): array
    {
        return json_decode(DB::table('ops_operational_state')->where('order_id',$order->id)->value('context')??'{}',true)['cargo_review']??[];
    }

    public function assertUnblocked(?int $loadId): void
    {
        if(!$loadId||!Schema::hasTable('ops_operational_state'))return;
        foreach(DB::table('ops_orders')->where('load_id',$loadId)->get() as $order){
            $review=$this->stored($order);
            app(OpsPortal::class)->assertDocumentsReady($order);
            abort_if(!empty($review['document_ids'])&&app(OperationalCompliance::class)->documentHashes($review['document_ids'])!==($review['document_hashes']??[]),409,'CI or packing-list evidence changed. A new provider review is required.');
            abort_if(in_array($review['status']??'',['pending_customer','rejected'],true),409,'Urgent cargo / price amendment requires customer acceptance before operations can continue.');
        }
    }

    private function values(object $load): array
    {
        return collect(self::FIELDS)->mapWithKeys(fn($key)=>[$key=>$load->$key??null])->all();
    }

    private function token(object $load,object $workspace): string
    {
        return hash('sha256',json_encode([$this->values($load),(string)$workspace->agreed_amount,$workspace->currency,$workspace->additional_charges]));
    }

    private function permissions(object $order,ShipmentWorkspace $workspace,User $user): array
    {
        $customer=(int)$workspace->customer_user_id===(int)$user->id;
        $provider=false;
        if(!$customer||$user->isSuperAdminOrMaster()){
            try {
                if(!$user->isSuperAdminOrMaster())app(AccountingAccess::class)->authorize($user,(int)$order->company_id,'ops');
                $provider=app(OpsTaskAccess::class)->manages($user,(int)$order->company_id)||(int)$workspace->provider_user_id===(int)$user->id
                    ||collect(app(OperationalCompliance::class)->read($order,false)['items'])->contains(fn($item)=>(int)($item['responsible_user_id']??0)===(int)$user->id&&in_array($item['key'],['documents_received','cargo_documents','goods_documents','check_storage_documents'],true));
            } catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==403)throw $e;}
        }
        return [$customer,$provider];
    }

    public function read(object $order,User $user): array
    {
        if(!$order->workspace_id||!$order->load_id)return isset(app(OpsPortal::class)->context($order)['intake'])?app(ExternalCargoReview::class)->read($order,$user):['available'=>false];
        $workspace=ShipmentWorkspace::findOrFail($order->workspace_id);$load=Load::findOrFail($order->load_id);
        [$customer,$provider]=$this->permissions($order,$workspace,$user);abort_unless($customer||$provider,403);
        $review=$this->stored($order);
        if(!empty($review['document_ids'])&&app(OperationalCompliance::class)->documentHashes($review['document_ids'])!==($review['document_hashes']??[]))$review['status']='review_required';
        return ['available'=>true,'review'=>$review,'revision'=>(int)($review['revision']??0),'load_fingerprint'=>$this->token($load,$workspace),
            'documents'=>DB::table('ops_order_documents')->join('documents','documents.id','=','ops_order_documents.document_id')->where('order_id',$order->id)->get(['documents.id','documents.name'])->unique('id')->values(),'current'=>$this->values($load),'agreed_total'=>Decimal::value((string)$workspace->agreed_amount),'currency'=>$workspace->currency,
            'can_report'=>$provider&&!in_array($order->status,['closed','cancelled'],true),
            'can_confirm'=>$customer&&($review['status']??'')==='pending_customer'&&(int)($review['reported_by']??0)!==(int)$user->id];
    }

    public static function rules(): array
    {
        $rules=['action'=>['required','in:report,approve,reject'],'revision'=>['required','integer','min:0'],
            'load_fingerprint'=>['required','string','size:64'],'ci_document_id'=>['required_if:action,report','integer'],
            'pl_document_id'=>['required_if:action,report','integer','different:ci_document_id'],
            'documents_received'=>['required_if:action,report','accepted_if:action,report'],'reason'=>['required','string','min:5','max:2000'],
            'proposed_total'=>['required_if:action,report','numeric','min:0.01','max:999999999.99'],
            'pricing_basis'=>['required_if:action,report','string','min:3','max:1000'],
            'actual'=>['required_if:action,report','array:'.implode(',',self::FIELDS)]];
        foreach(self::FIELDS as $field)$rules['actual.'.$field]=in_array($field,['goods_type','cargo_type','quantity_measure'],true)?['nullable','string','max:255']:['nullable',$field==='pallets'?'integer':'numeric','min:0','max:999999999'];
        return $rules;
    }

    public function act(object $order,User $user,array $data): array
    {
        if(!$order->workspace_id)return app(ExternalCargoReview::class)->act($order,$user,$data);
        return DB::transaction(function()use($order,$user,$data){
            $ops=app(OperationalCompliance::class);$order=$ops->order((int)$order->company_id,(int)$order->id,true);
            abort_if(in_array($order->status,['closed','cancelled'],true),422,'Reopen this order before requesting an amendment.');
            abort_unless($order->workspace_id&&$order->load_id,422,'Cargo amendments require a booked shipment.');
            $workspace=ShipmentWorkspace::whereKey($order->workspace_id)->lockForUpdate()->firstOrFail();
            $load=Load::whereKey($order->load_id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($workspace->status,['completed','cancelled'],true),422,'This shipment is no longer open for amendments.');
            [$customer,$provider]=$this->permissions($order,$workspace,$user);
            $before=$this->stored($order);$state=$ops->read($order,false);
            abort_unless((int)($before['revision']??0)===$data['revision'],409,'The proposal changed. Refresh before continuing.');
            abort_unless(hash_equals($this->token($load,$workspace),$data['load_fingerprint']),409,'Cargo or commercial terms changed. Refresh the proposal.');
            $items=$state['items'];
            if($data['action']==='report'){
                abort_unless($provider,403,'Only the responsible service provider can propose an amendment.');
                if(collect($items)->contains('key','documents_received'))abort_unless(app(OpsPortal::class)->documentState($order)['receipt_valid'],422,'Confirm receipt of the CI and PL first.');
                $ids=[(int)$data['ci_document_id'],(int)$data['pl_document_id']];if(collect($items)->contains('key','documents_received'))abort_unless((app(OpsPortal::class)->documentState($order)['receipt']['document_ids']??[])===$ids,409,'Confirm receipt of these CI/PL files first.');abort_if($ids[0]===$ids[1],422,'Select a separate CI and packing list.');
                app(TransportFlow::class)->documents($order,$ids);
                $actual=array_replace($this->values($load),array_intersect_key($data['actual'],array_flip(self::FIELDS)));
                $changes=[];
                foreach($actual as $key=>$value){$old=$load->$key??null;if(($old===null||$value===null)?$old!==$value:((is_numeric($old)&&is_numeric($value))?bccomp((string)$old,(string)$value,6)!==0:(string)$old!==(string)$value))$changes[$key]=['before'=>$old,'after'=>$value];}
                $newTotal=Decimal::value((string)$data['proposed_total']);$priceChanged=bccomp($newTotal,(string)$workspace->agreed_amount,2)!==0;
                $pending=$changes!==[]||$priceChanged;
                $review=['revision'=>$data['revision']+1,'status'=>$pending?'pending_customer':'verified','changes'=>$changes,'actual'=>$actual,
                    'previous_total'=>Decimal::value((string)$workspace->agreed_amount),'proposed_total'=>$newTotal,'currency'=>$workspace->currency,
                    'pricing_basis'=>$data['pricing_basis'],'reason'=>$data['reason'],'document_ids'=>$ids,'document_hashes'=>$ops->documentHashes($ids),
                    'reported_by'=>$user->id,'reported_at'=>now()->toIso8601String(),'baseline_token'=>$this->token($load,$workspace)];
            }else{
                abort_unless($customer&&(int)($before['reported_by']??0)!==(int)$user->id,403,'Only the shipment customer can accept or reject this proposal.');
                if(collect($items)->contains('key','documents_received'))abort_unless(app(OpsPortal::class)->documentState($order)['receipt_valid']&&(app(OpsPortal::class)->documentState($order)['receipt']['document_ids']??[])===$before['document_ids']&&(int)($before['revision']??0)>(int)(app(OpsPortal::class)->documentState($order)['receipt']['review_revision']??0),409,'The document receipt changed. A new provider review is required.');
                abort_unless(($before['status']??'')==='pending_customer',409,'There is no pending customer proposal.');
                abort_unless(hash_equals($before['baseline_token'],$this->token($load,$workspace)),409,'The original cargo or price changed. A new proposal is required.');
                abort_unless($ops->documentHashes($before['document_ids'])===$before['document_hashes'],409,'CI or packing-list evidence changed. A new proposal is required.');
                $review=array_merge($before,['revision'=>$data['revision']+1,'status'=>$data['action']==='approve'?'accepted':'rejected',
                    'decided_by'=>$user->id,'decided_at'=>now()->toIso8601String(),'decision_reason'=>$data['reason']]);
                if($data['action']==='approve'){
                    $values=collect($before['changes'])->mapWithKeys(fn($change,$key)=>[$key=>$change['after']])->all();
                    if($values)$load->fill($values)->save();
                    $delta=bcsub($before['proposed_total'],(string)$workspace->agreed_amount,2);
                    $charges=$workspace->additional_charges??[];
                    if(bccomp($delta,'0',2)!==0)$charges[]=['id'=>(string)Str::uuid(),'type'=>'Accepted cargo / price amendment','condition'=>$before['reason'],'rate'=>$delta,'unit'=>'shipment',
                        'approved'=>true,'approved_at'=>now()->toIso8601String(),'approved_by'=>$user->id,'source'=>'cargo_reconciliation','review_revision'=>$before['revision']];
                    // Preserve the accepted offer snapshot; append actual cargo and the amendment reference.
                    $snapshot=$workspace->load_snapshot??[];$snapshot['actual_cargo']=$before['actual'];$snapshot['cargo_amendment_revision']=$review['revision'];
                    $workspace->update(['agreed_amount'=>$before['proposed_total'],'additional_charges'=>$charges,'load_snapshot'=>$snapshot]);
                    DB::table('ops_orders')->where('id',$order->id)->update(['agreed_revenue'=>$before['proposed_total'],'updated_at'=>now()]);
                    foreach($items as &$item)if(!in_array($item['key'],['cargo_documents','cargo_reconciliation','booking','booking_confirmation'],true)&&($item['status']??'')==='completed'){
                        $item['previous_completion']=['at'=>$item['completed_at']??null,'by'=>$item['completed_by_user_id']??null];$item['status']='pending';$item['needs_review']=true;$item['completed_at']=null;$item['completed_by_user_id']=null;
                    }unset($item);
                }
            }
            $pending=in_array($review['status'],['pending_customer','rejected'],true);
            $items=array_values(array_filter($items,fn($item)=>!in_array($item['key'],['cargo_documents','cargo_reconciliation'],true)));
            $items[]=['key'=>'cargo_documents','label'=>'Receive and verify commercial invoice + packing list','status'=>$pending?'blocked':'completed','controlled'=>true,
                'plan_version'=>2,'required_documents'=>['Commercial invoice','Packing list'],'responsible_user_id'=>$workspace->provider_user_id,'document_ids'=>$review['document_ids'],'document_hashes'=>$review['document_hashes'],
                'next_action'=>$pending?'Await customer acceptance of cargo and price amendment':'CI and packing list verified','completed_by_user_id'=>$pending?null:$user->id,'completed_at'=>$pending?null:now()->toIso8601String()];
            if($pending)$items[]=['key'=>'cargo_reconciliation','label'=>'URGENT: approve cargo data and revised price','status'=>'blocked','controlled'=>true,'plan_version'=>2,'urgent'=>true,
                'audience'=>'customer','responsible_user_id'=>$workspace->customer_user_id,'due_date'=>now()->toDateString(),'next_action'=>'Customer must accept the full cargo and price proposal before operations continue.'];
            $items=app(OperationsPlan::class)->withRequirements($items,$load->toArray(),(int)$workspace->provider_user_id);
            $workspace->update(['operational_checklist'=>$items]);$ops->initialize($order);
            $context=$state['context'];$context['cargo_review']=$review;
            DB::table('ops_operational_state')->where('order_id',$order->id)->update(['context'=>json_encode($context),'revision'=>$state['revision']+1,'updated_at'=>now()]);
            DB::table('ops_orders')->where('id',$order->id)->increment('revision');
            $ops->audit((int)$order->company_id,'cargo_review',(int)$order->id,(int)$user->id,$data['action'],$before,$review);
            return $this->read($ops->order((int)$order->company_id,(int)$order->id),$user);
        });
    }
}
