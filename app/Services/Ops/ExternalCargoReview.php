<?php
namespace App\Services\Ops;
use App\Models\User;
use App\Services\OperationalCompliance;
use App\Services\Accounting\Decimal;
use Illuminate\Support\Facades\DB;

/** The same explicit commercial acceptance for an external order, without creating a fake load. */
final class ExternalCargoReview
{
    private function current(object $order): array {return app(OpsPortal::class)->context($order)['intake']['cargo']??[];}
    private function token(object $order): string {return hash('sha256',json_encode([$this->current($order),(string)$order->agreed_revenue,$order->currency]));}
    public function read(object $order,User $user): array {
        $portal=app(OpsPortal::class);$provider=$portal->provider($order,$user);$buyer=$portal->buyer($order,$user);abort_unless($provider||$buyer,403);
        $review=$portal->context($order)['cargo_review']??[];
        if(!empty($review['document_ids'])&&app(OperationalCompliance::class)->documentHashes($review['document_ids'])!==($review['document_hashes']??[]))$review['status']='review_required';
        return ['available'=>true,'review'=>$review,'revision'=>(int)($review['revision']??0),'load_fingerprint'=>$this->token($order),'documents'=>$portal->documents($order),
            'current'=>$this->current($order),'agreed_total'=>$order->agreed_revenue,'currency'=>$order->currency,'can_report'=>$provider&&!in_array($order->status,['closed','cancelled'],true),
            'can_confirm'=>$buyer&&($review['status']??'')==='pending_customer'&&(int)($review['reported_by']??0)!==(int)$user->id];
    }
    public function act(object $order,User $user,array $data): array {
        return DB::transaction(function()use($order,$user,$data){
            $ops=app(OperationalCompliance::class);$portal=app(OpsPortal::class);$order=$ops->order((int)$order->company_id,(int)$order->id,true);
            abort_if(in_array($order->status,['closed','cancelled'],true),422);app(OpsOrders::class)->assertConfirmed($order);
            $context=$portal->context($order);$before=$context['cargo_review']??[];
            abort_unless((int)($before['revision']??0)===$data['revision']&&hash_equals($this->token($order),$data['load_fingerprint']),409,'The external order or proposal changed. Refresh first.');
            if($data['action']==='report'){
                abort_unless($portal->provider($order,$user),403);abort_unless($portal->documentState($order)['receipt_valid'],422,'Confirm receipt of Commercial invoice and packing list first.');
                $ids=[(int)$data['ci_document_id'],(int)$data['pl_document_id']];abort_unless(($portal->documentState($order)['receipt']['document_ids']??[])===$ids,409,'Receive these commercial invoice and packing list documents first.');app(TransportFlow::class)->documents($order,$ids);abort_if($ids[0]===$ids[1],422);
                $actual=array_replace($this->current($order),$data['actual']);$changes=[];
                foreach($actual as $key=>$value){$old=$this->current($order)[$key]??null;if(($old===null||$value===null)?$old!==$value:((is_numeric($old)&&is_numeric($value))?bccomp((string)$old,(string)$value,6)!==0:(string)$old!==(string)$value))$changes[$key]=['before'=>$old,'after'=>$value];}
                $total=Decimal::value((string)$data['proposed_total']);$pending=$changes||$order->agreed_revenue===null||bccomp($total,(string)$order->agreed_revenue,2)!==0;
                $review=['revision'=>$data['revision']+1,'status'=>$pending?'pending_customer':'verified','actual'=>$actual,'changes'=>$changes,'previous_total'=>$order->agreed_revenue,'proposed_total'=>$total,'currency'=>$order->currency,
                    'reason'=>$data['reason'],'pricing_basis'=>$data['pricing_basis'],'document_ids'=>$ids,'document_hashes'=>$ops->documentHashes($ids),'reported_by'=>$user->id,'reported_at'=>now()->toIso8601String(),'baseline_token'=>$this->token($order)];
            }else{
                abort_unless($portal->buyer($order,$user)&&(int)($before['reported_by']??0)!==(int)$user->id,403);
                abort_unless($portal->documentState($order)['receipt_valid']&&($portal->documentState($order)['receipt']['document_ids']??[])===$before['document_ids']&&(int)($before['revision']??0)>(int)(app(OpsPortal::class)->documentState($order)['receipt']['review_revision']??0),409,'The document receipt changed.');
                abort_unless(($before['status']??'')==='pending_customer'&&hash_equals($before['baseline_token'],$this->token($order))&&$ops->documentHashes($before['document_ids'])===$before['document_hashes'],409,'The proposal or evidence changed.');
                $review=$before+[];$review['revision']=$data['revision']+1;$review['status']=$data['action']==='approve'?'accepted':'rejected';$review['decided_by']=$user->id;$review['decided_at']=now()->toIso8601String();$review['decision_reason']=$data['reason'];
                if($data['action']==='approve'){
                    $context['intake']['original_cargo']??=$context['intake']['cargo']??[];$context['intake']['cargo']=$before['actual'];
                    DB::table('ops_orders')->where('id',$order->id)->update(['agreed_revenue'=>$before['proposed_total'],'updated_at'=>now()]);
                }
            }
            $context['cargo_review']=$review;DB::table('ops_operational_state')->where('order_id',$order->id)->update(['context'=>json_encode($context),'updated_at'=>now()]);DB::table('ops_operational_state')->where('order_id',$order->id)->increment('revision');DB::table('ops_orders')->where('id',$order->id)->increment('revision');
            $ops->audit((int)$order->company_id,'cargo_review',(int)$order->id,(int)$user->id,$data['action'],$before,$review);
            return $this->read($ops->order((int)$order->company_id,(int)$order->id),$user);
        });
    }
}
