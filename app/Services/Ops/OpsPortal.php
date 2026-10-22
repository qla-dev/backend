<?php
namespace App\Services\Ops;

use App\Models\User;
use App\Models\ShipmentWorkspace;
use App\Services\Accounting\AccountingAccess;
use App\Services\OperationalCompliance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Order-specific customer access. A customer never becomes a company team member here. */
final class OpsPortal
{
    public function buyer(object $order, User $user): bool
    {
        if($order->workspace_id && (int)DB::table('shipment_workspace')->where('id',$order->workspace_id)->value('customer_user_id')===(int)$user->id)return true;
        return Schema::hasTable('ops_order_participants') && DB::table('ops_order_participants')->where('order_id',$order->id)->where('user_id',$user->id)->where('status','active')->exists();
    }

    public function provider(object $order, User $user): bool
    {
        try { app(AccountingAccess::class)->authorize($user,(int)$order->company_id,'ops'); }
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()===403)return false;throw $e;}
        return app(OpsTaskAccess::class)->manages($user,(int)$order->company_id)||(int)$order->responsible_user_id===(int)$user->id
            ||collect($this->rawItems($order))->contains(fn($item)=>(int)($item['responsible_user_id']??0)===(int)$user->id&&in_array($item['key'],['documents_received','cargo_documents','goods_documents','check_storage_documents'],true));
    }

    public function rawItems(object $order): array
    {
        return $order->workspace_id ? (ShipmentWorkspace::find($order->workspace_id)?->operational_checklist??[]) : json_decode(DB::table('ops_operational_state')->where('order_id',$order->id)->value('items')??'[]',true);
    }

    public function context(object $order): array
    {
        return json_decode(DB::table('ops_operational_state')->where('order_id',$order->id)->value('context')??'{}',true);
    }

    public function documentState(object $order): array
    {
        $context=$this->context($order);$intake=$context['document_intake']??[];$receipt=$intake['receipt']??null;
        $review=$context['cargo_review']??[];
        $valid=$receipt && app(OperationalCompliance::class)->documentHashes($receipt['document_ids'])===($receipt['document_hashes']??[]);
        $reviewValid=$receipt && ($review['document_ids']??[])===($receipt['document_ids']??[]) && (int)($review['revision']??0)>(int)($receipt['review_revision']??0) && in_array($review['status']??'',['verified','accepted'],true) && app(OperationalCompliance::class)->documentHashes($review['document_ids']??[])===($review['document_hashes']??[]);
        return ['required'=>['ci','pl'],'receipt'=>$receipt,'receipt_valid'=>(bool)$valid,'review_complete'=>(bool)($valid&&$reviewValid),'reviewed_at'=>$review['decided_at']??$review['reported_at']??null,
            'status'=>$valid?($reviewValid?'completed':'in_progress'):($receipt?'review_required':'pending')];
    }

    public function ensureDocumentOperation(object $order): void
    {
        if(in_array($order->status,['closed','cancelled'],true)||($order->workspace_id&&in_array(DB::table('shipment_workspace')->where('id',$order->workspace_id)->value('status'),['completed','cancelled'],true)))return;
        $ops=app(OperationalCompliance::class);$ops->initialize($order);$items=$this->rawItems($order);
        if(!$order->workspace_id){$context=$this->context($order);if(!isset($context['intake'])){$context['intake']=['kind'=>'order','source'=>'manual','department'=>'road','cargo'=>[]];DB::table('ops_operational_state')->where('order_id',$order->id)->update(['context'=>json_encode($context)]);}}
        if(collect($items)->contains('key','documents_received'))return;
        array_unshift($items,['key'=>'documents_received','label'=>'Prijem i provjera dokumentacije','status'=>'pending','controlled'=>true,'plan_version'=>2,
            'responsible_user_id'=>$order->responsible_user_id,'audience'=>'provider','source'=>'document_intake','required_documents'=>['CI','PL']]);
        if($order->workspace_id)ShipmentWorkspace::findOrFail($order->workspace_id)->update(['operational_checklist'=>$items]);
        else DB::table('ops_operational_state')->where('order_id',$order->id)->update(['items'=>json_encode($items)]);
        DB::table('ops_operational_state')->where('order_id',$order->id)->increment('revision');
    }

    public function deriveDocumentOperation(object $order,array $items): array
    {
        $state=$this->documentState($order);
        return array_map(function($item)use($state){if(($item['key']??'')==='documents_received'){
            $item['status']=$state['status']==='review_required'?'blocked':$state['status'];$item['controlled']=true;
            $item['next_action']=$state['review_complete']?'Dokumentacija provjerena':($state['receipt_valid']?'Provjeriti podatke CI/PL i potvrditi uslove':'Kupac dostavlja CI i PL; odgovorna osoba potvrđuje prijem');
            $item['completed_at']=$state['review_complete']?($state['reviewed_at']??null):null;
        }return $item;},$items);
    }

    public function assertDocumentsReady(object $order): void
    {
        if(!collect($this->rawItems($order))->contains('key','documents_received'))return;
        abort_unless($this->documentState($order)['review_complete'],409,'Receive and review the required CI/PL documentation in Transport orders before continuing.');
    }

    public function documents(object $order): array
    {
        return DB::table('ops_order_documents')->join('documents','documents.id','=','ops_order_documents.document_id')->where('order_id',$order->id)
            ->get(['documents.id','documents.name','documents.type','ops_order_documents.role'])->unique('id')->values()->map(fn($x)=>(array)$x)->all();
    }

    public function attach(object $order,User $user,int $documentId,string $role): array
    {
        return DB::transaction(function()use($order,$user,$documentId,$role){
            $order=app(OperationalCompliance::class)->order((int)$order->company_id,(int)$order->id,true);
            abort_unless($this->buyer($order,$user)||$this->provider($order,$user),403);
            abort_if(in_array($order->status,['closed','cancelled'],true),422,'This order is read-only.');
            $doc=DB::table('documents')->where('id',$documentId)->where('uploaded_by_user_id',$user->id)->first()??abort(404);
            abort_if(!empty($doc->vehicle_id)||!empty($doc->user_id)||!empty($doc->load_draft_id)||(!empty($doc->load_id)&&(int)$doc->load_id!==(int)$order->load_id),422,'Use a document for this order only.');
            abort_unless(app(OperationalCompliance::class)->documentHashes([$documentId]),422,'The document file is unavailable.');
            $this->ensureDocumentOperation($order);
            if(!DB::table('ops_order_documents')->where('order_id',$order->id)->where('document_id',$documentId)->exists()){
                DB::table('ops_order_documents')->insert(['order_id'=>$order->id,'document_id'=>$documentId,'role'=>$role,'created_at'=>now()]);
                $ops=app(OperationalCompliance::class);$ops->audit((int)$order->company_id,'ops_order',(int)$order->id,(int)$user->id,'document_supplied',null,['document_id'=>$documentId,'role'=>$role]);
                // New evidence needs a new receipt/review; a prior acceptance is never silently reused.
                $context=$this->context($order);if(in_array($role,['ci','pl'],true))$context['document_intake']['receipt']=null;
                DB::table('ops_operational_state')->where('order_id',$order->id)->update(['context'=>json_encode($context),'updated_at'=>now()]);
                DB::table('ops_operational_state')->where('order_id',$order->id)->increment('revision');
            }
            return $this->documents($order);
        });
    }

    public function receive(object $order,User $user,array $data): array
    {
        return DB::transaction(function()use($order,$user,$data){
            $ops=app(OperationalCompliance::class);$order=$ops->order((int)$order->company_id,(int)$order->id,true);
            abort_unless($this->provider($order,$user),403);abort_if(in_array($order->status,['closed','cancelled'],true),422);
            app(OpsOrders::class)->assertConfirmed($order);
            $assigned=collect($this->rawItems($order))->firstWhere('key','documents_received')['responsible_user_id']??$order->responsible_user_id;
            abort_unless($user->isSuperAdminOrMaster()||!$assigned||(int)$assigned===(int)$user->id,403,'The assigned documentation operator must confirm receipt.');
            $state=DB::table('ops_operational_state')->where('order_id',$order->id)->first();abort_unless((int)$state->revision===$data['revision'],409,'Documents changed. Refresh before confirming receipt.');
            $ids=[(int)$data['ci_document_id'],(int)$data['pl_document_id']];abort_if($ids[0]===$ids[1],422,'CI and PL must be separate documents.');
            app(TransportFlow::class)->documents($order,$ids);$hashes=$ops->documentHashes($ids);abort_unless(count($hashes)===2,422,'Both document files must be available.');
            $context=$this->context($order);$context['document_intake']['receipt']=['document_ids'=>$ids,'document_hashes'=>$hashes,'review_revision'=>(int)($context['cargo_review']['revision']??0),'received_by'=>$user->id,'received_at'=>now()->toIso8601String()];
            DB::table('ops_operational_state')->where('order_id',$order->id)->update(['context'=>json_encode($context),'revision'=>$state->revision+1,'updated_at'=>now()]);
            $ops->audit((int)$order->company_id,'ops_order',(int)$order->id,(int)$user->id,'documents_received',null,$context['document_intake']['receipt']);
            return $this->documentState($order);
        });
    }

    public function linkedCustomer(object $order): ?int
    {
        $link=app(OpsOrders::class)->intake($order)['customer_link']??null;if(!$link)return null;
        $id=match($link['type']){
            'customer'=>DB::table('customers')->where('id',$link['id'])->value('user_id'),
            'company'=>DB::table('companies')->where('id',$link['id'])->value('owner_user_id'),
            'partner'=>DB::table('customers')->where('id',DB::table('accounting_partners')->where('company_id',$order->company_id)->where('id',$link['id'])->value('customer_id'))->value('user_id'),
            default=>null};return $id?(int)$id:null;
    }

    public function invite(object $order,User $user): object
    {
        return DB::transaction(function()use($order,$user){
            $order=app(OperationalCompliance::class)->order((int)$order->company_id,(int)$order->id,true);
            abort_unless($this->provider($order,$user),403);abort_if($order->workspace_id||in_array($order->status,['closed','cancelled'],true),422,'Booked customers already have access to their shipment.');
            $target=$this->linkedCustomer($order);abort_unless($target&&$target!==(int)$user->id,422,'Select an existing customer with a different FreightBook user profile first.');
            $old=DB::table('ops_order_participants')->where('order_id',$order->id)->where('user_id',$target)->first();
            if($old&&($old->status==='active'||($old->status==='invited'&&$old->expires_at&&\Carbon\Carbon::parse($old->expires_at)->isFuture())))return $old;
            DB::table('ops_order_participants')->updateOrInsert(['order_id'=>$order->id,'user_id'=>$target],['role'=>'customer','status'=>'invited','revision'=>($old->revision??0)+1,'invited_by'=>$user->id,'expires_at'=>now()->addDays(7),'accepted_at'=>null,'revoked_at'=>null,'created_at'=>$old->created_at??now(),'updated_at'=>now()]);
            $record=DB::table('ops_order_participants')->where('order_id',$order->id)->where('user_id',$target)->first();
            app(OperationalCompliance::class)->audit((int)$order->company_id,'ops_order',(int)$order->id,(int)$user->id,'customer_invited',$old,$record);return $record;
        });
    }

    public function participantAction(object $order,User $user,string $action,int $revision): object
    {
        return DB::transaction(function()use($order,$user,$action,$revision){
            $order=app(OperationalCompliance::class)->order((int)$order->company_id,(int)$order->id,true);
            $query=DB::table('ops_order_participants')->where('order_id',$order->id);
            if($action==='accept')$query->where('user_id',$user->id);else{abort_unless($this->provider($order,$user),403);$query->where('user_id',$this->linkedCustomer($order));}
            $old=$query->lockForUpdate()->first()??abort(404);abort_unless((int)$old->revision===$revision,409,'The invitation changed. Refresh first.');
            if($action==='accept')abort_unless($old->status==='invited'&&$old->expires_at&&\Carbon\Carbon::parse($old->expires_at)->isFuture(),422,'The invitation expired or is no longer pending.');
            else abort_unless(in_array($old->status,['active','invited'],true),422);
            $query->update(['status'=>$action==='accept'?'active':'revoked','revision'=>$old->revision+1,$action==='accept'?'accepted_at':'revoked_at'=>now(),'updated_at'=>now()]);
            app(OperationalCompliance::class)->audit((int)$order->company_id,'ops_order',(int)$order->id,(int)$user->id,'customer_'.$action,$old,['user_id'=>$old->user_id]);return $query->first();
        });
    }
}
