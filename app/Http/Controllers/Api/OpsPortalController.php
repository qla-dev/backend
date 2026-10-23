<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\ShipmentWorkspace;
use App\Services\Ops\OpsPortal;
use App\Services\Ops\CargoReview;
use App\Services\Ops\OperationalSignals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class OpsPortalController extends Controller
{
    public function __construct(private OpsPortal $portal){}
    private function order(Request $r): object {return DB::table('ops_orders')->where('id',$r->route('order'))->first()??abort(404);}
    private function response($data){return response()->json(['data'=>$data]);}
    public function syncMine(Request $r){
        if(Schema::hasTable('offers'))app(\App\Services\OfferBooking::class)->repairMine($r->user());
        app(\App\Services\Ops\SupplyChain::class)->syncInvitations($r->user());
        $created=0;foreach(ShipmentWorkspace::where('customer_user_id',$r->user()->id)->whereNotNull('provider_company_id')->whereNotNull('booked_at')->orderBy('id')->limit(300)->get() as $workspace){
            $existed=DB::table('ops_orders')->where('workspace_id',$workspace->id)->exists();$order=app(\App\Services\Ops\OpsOrders::class)->createFromWorkspace($workspace);if($order){$this->portal->ensureDocumentOperation($order);if(!$existed)$created++;}
        }return $this->response(['created'=>$created]);
    }
    public function index(Request $r){
        $user=$r->user();$orders=DB::table('ops_orders')->whereIn('workspace_id',DB::table('shipment_workspace')->where('customer_user_id',$user->id)->select('id'));
        if(Schema::hasTable('ops_order_participants'))$orders->orWhereIn('id',DB::table('ops_order_participants')->where('user_id',$user->id)->whereIn('status',['invited','active'])->select('order_id'));
        $rows=$orders->latest('id')->limit(200)->get()->map(function($order)use($user){$participant=Schema::hasTable('ops_order_participants')?DB::table('ops_order_participants')->where('order_id',$order->id)->where('user_id',$user->id)->first():null;
            return ['id'=>$order->id,'reference'=>$order->reference,'title'=>$order->title,'status'=>$order->status,'provider'=>DB::table('companies')->where('id',$order->company_id)->value('name'),'workspace_id'=>$order->workspace_id,'load_id'=>$order->load_id,
                'access'=>$this->portal->buyer($order,$user)?'active':(app(\App\Services\Ops\SupplyChain::class)->supplier($order,$user)?'supplier':'invited'),'invitation'=>$participant?collect((array)$participant)->only(['status','revision','expires_at'])->all():null];});
        // Bookings without a logistics-company work order remain visible without inventing a company.
        $assigned=ShipmentWorkspace::where(function($q)use($user){$q->where('provider_user_id',$user->id)->orWhere(function($q)use($user){$q->where('customer_user_id',$user->id)->whereNotIn('id',DB::table('ops_orders')->whereNotNull('workspace_id')->select('workspace_id'));});if(Schema::hasColumn('loads','assigned_driver_user_id'))$q->orWhereHas('freightLoad',fn($loads)=>$loads->where('assigned_driver_user_id',$user->id));});
        foreach($assigned->latest('id')->limit(100)->get() as $w){
            if($rows->contains(fn($row)=>(int)$row['workspace_id']===(int)$w->id))continue;
            $linked=DB::table('ops_orders')->where('workspace_id',$w->id)->first();if($linked&&$this->portal->provider($linked,$user))continue;
            $rows->push(['id'=>null,'reference'=>$w->reference,'title'=>$w->load_snapshot['title']??$w->reference,'status'=>$w->status,'workspace_id'=>$w->id,'load_id'=>$w->load_id,'access'=>(int)$w->customer_user_id===(int)$user->id?'booking':'assigned','provider'=>null]);
        }
        return $this->response($rows->values());
    }
    public function show(Request $r){
        $order=$this->order($r);$supply=app(\App\Services\Ops\SupplyChain::class);
        if($supply->supplier($order,$r->user())&&!$supply->manages($order,$r->user()))return $this->response(['supplier'=>true,'order'=>['id'=>$order->id,'reference'=>$order->reference,'title'=>$order->title],'supply_chain'=>$supply->read($order,$r->user())]);
        abort_unless($this->portal->buyer($order,$r->user())||$this->portal->provider($order,$r->user()),403);
        return $this->response(['order'=>collect((array)$order)->only(['id','reference','title','status','load_id','workspace_id'])->all(),'documents'=>$this->portal->documents($order),
            'intake'=>$this->portal->documentState($order),'timeline'=>app(OperationalSignals::class)->orderTimeline($order)]);
    }
    public function prepare(Request $r){$order=$this->order($r);app(\App\Services\Accounting\AccountingAccess::class)->authorize($r->user(),(int)$order->company_id,'ops');if($this->portal->provider($order,$r->user()))$this->portal->ensureDocumentOperation($order);return $this->response(['ready'=>true]);}
    public function linkCustomer(Request $r){
        $order=$this->order($r);abort_unless($this->portal->provider($order,$r->user()),403);abort_if($order->workspace_id,422,'The booking customer comes from the accepted offer.');
        $data=$r->validate(['type'=>['required','in:customer,company,partner'],'id'=>['required','integer','min:1']]);
        return DB::transaction(function()use($order,$r,$data){$order=app(\App\Services\OperationalCompliance::class)->order((int)$order->company_id,(int)$order->id,true);abort_if(in_array($order->status,['closed','cancelled'],true),422);
            abort_if(DB::table('ops_order_participants')->where('order_id',$order->id)->where('role','customer')->whereIn('status',['active','invited'])->exists(),422,'Revoke current access before changing the linked customer.');
            $q=DB::table(match($data['type']){'customer'=>'customers','company'=>'companies','partner'=>'accounting_partners'})->where('id',$data['id']);if($data['type']==='partner')$q->where('company_id',$order->company_id);$customer=$q->first()??abort(404);
            $context=$this->portal->context($order);abort_if(($context['cargo_review']['status']??'')==='pending_customer',409,'Resolve the pending cargo and price proposal before changing the customer.');$context['intake']['customer_link']=$data;
            DB::table('ops_operational_state')->where('order_id',$order->id)->update(['context'=>json_encode($context),'updated_at'=>now()]);DB::table('ops_operational_state')->where('order_id',$order->id)->increment('revision');
            $name=$data['type']==='customer'?($customer->company_name?:DB::table('users')->where('id',$customer->user_id)->value('name')):$customer->name;
            DB::table('ops_orders')->where('id',$order->id)->update(['customer_name'=>$name,'updated_at'=>now()]);DB::table('ops_orders')->where('id',$order->id)->increment('revision');
            app(\App\Services\OperationalCompliance::class)->audit((int)$order->company_id,'ops_order',(int)$order->id,(int)$r->user()->id,'customer_linked',null,$data);return $this->response(['linked'=>true]);});
    }
    public function documents(Request $r){$order=$this->order($r);$data=$r->validate(['document_id'=>['required','integer'],'role'=>['required','in:ci,pl,cmr,pod,other']]);return $this->response($this->portal->attach($order,$r->user(),$data['document_id'],$data['role']));}
    public function supplyChain(Request $r){
        $order=$this->order($r);$service=app(\App\Services\Ops\SupplyChain::class);if($r->isMethod('get'))return $this->response($service->read($order,$r->user()));
        $data=$r->validate(['action'=>['required','in:invite,revoke,update'],'contact_id'=>['required_unless:action,invite','uuid'],'revision'=>['required_unless:action,invite','integer','min:1'],'company'=>['required_if:action,invite','string','max:255'],'name'=>['required_if:action,invite','string','max:255'],'email'=>['required_if:action,invite','email','max:255'],'phone'=>['sometimes','nullable','string','max:80'],'role'=>['required_if:action,invite','in:supplier,exporter,shipper'],'required_documents'=>['required_if:action,invite','array','min:1','max:4'],'required_documents.*'=>['distinct','in:ci,pl,cmr,other'],'due_date'=>['nullable','date'],'request_note'=>['nullable','string','max:2000'],'cargo_ready'=>['sometimes','boolean'],'pickup_address'=>['sometimes','nullable','string','max:500'],'pickup_note'=>['sometimes','nullable','string','max:2000']]);
        return $this->response($service->act($order,$r->user(),$data));
    }
    public function assignDocuments(Request $r){
        $order=$this->order($r);app(\App\Services\Accounting\AccountingAccess::class)->authorize($r->user(),(int)$order->company_id,'ops');
        abort_unless(app(\App\Services\Ops\OpsTaskAccess::class)->manages($r->user(),(int)$order->company_id),403);
        $data=$r->validate(['revision'=>['required','integer','min:0'],'responsible_user_id'=>['required','integer']]);
        return DB::transaction(function()use($order,$r,$data){$ops=app(\App\Services\OperationalCompliance::class);$order=$ops->order((int)$order->company_id,(int)$order->id,true);abort_if(in_array($order->status,['closed','cancelled'],true),422);$ops->member((int)$order->company_id,$data['responsible_user_id']);
            $state=DB::table('ops_operational_state')->where('order_id',$order->id)->first();abort_unless((int)$state->revision===$data['revision'],409,'The document operation changed. Refresh first.');
            $items=$this->portal->rawItems($order);foreach($items as &$item)if($item['key']==='documents_received'){$item['responsible_user_id']=$data['responsible_user_id'];$item['assignment_source']='manual';}unset($item);
            if($order->workspace_id)ShipmentWorkspace::findOrFail($order->workspace_id)->update(['operational_checklist'=>$items]);else DB::table('ops_operational_state')->where('order_id',$order->id)->update(['items'=>json_encode($items)]);
            DB::table('ops_operational_state')->where('order_id',$order->id)->increment('revision');$ops->audit((int)$order->company_id,'ops_order',(int)$order->id,(int)$r->user()->id,'documentation_assigned',null,$data);return $this->response(['assigned'=>true]);});
    }
    public function receive(Request $r){$order=$this->order($r);$data=$r->validate(['revision'=>['required','integer','min:0'],'ci_document_id'=>['required','integer'],'pl_document_id'=>['required','integer','different:ci_document_id']]);return $this->response($this->portal->receive($order,$r->user(),$data));}
    public function accept(Request $r){$data=$r->validate(['revision'=>['required','integer','min:1']]);return $this->response($this->portal->participantAction($this->order($r),$r->user(),'accept',$data['revision']));}
    public function invite(Request $r){return $this->response($this->portal->invite($this->order($r),$r->user()));}
    public function revoke(Request $r){$data=$r->validate(['revision'=>['required','integer','min:1']]);return $this->response($this->portal->participantAction($this->order($r),$r->user(),'revoke',$data['revision']));}
    public function participants(Request $r){$order=$this->order($r);abort_unless($this->portal->provider($order,$r->user()),403);return $this->response(DB::table('ops_order_participants')->where('order_id',$order->id)->where('role','customer')->get(['user_id','status','revision','expires_at','accepted_at']));}
    public function review(Request $r){$order=$this->order($r);$service=app(CargoReview::class);$data=$r->isMethod('get')?$service->read($order,$r->user()):$service->act($order,$r->user(),$r->validate($service::rules()));
        if($data['available']??false){$data['document_intake']=$this->portal->documentState($order);$data['intake_revision']=(int)DB::table('ops_operational_state')->where('order_id',$order->id)->value('revision');$data['can_upload']=!in_array($order->status,['closed','cancelled'],true);$data['can_receive']=$this->portal->provider($order,$r->user());$data['order_id']=$order->id;$data['can_report']=($data['can_report']??false)&&$data['document_intake']['receipt_valid'];$data['can_confirm']=($data['can_confirm']??false)&&$data['document_intake']['receipt_valid']&&(int)($data['review']['revision']??0)>(int)($data['document_intake']['receipt']['review_revision']??0);$assigned=collect($this->portal->rawItems($order))->firstWhere('key','documents_received')['responsible_user_id']??$order->responsible_user_id;$data['can_receive']=$data['can_receive']&&(!$assigned||(int)$assigned===(int)$r->user()->id||$r->user()->isSuperAdminOrMaster());}
        return $this->response($data);
    }
    public function download(Request $r){$order=$this->order($r);abort_unless($this->portal->buyer($order,$r->user())||$this->portal->provider($order,$r->user())||app(\App\Services\Ops\SupplyChain::class)->documentVisible($order,$r->user(),(int)$r->route('document')),403);
        $document=DB::table('ops_order_documents')->join('documents','documents.id','=','ops_order_documents.document_id')->where('order_id',$order->id)->where('document_id',$r->route('document'))->first(['documents.*'])??abort(404);
        $path='documents/'.$document->path;abort_unless(Storage::disk('local')->exists($path),404);return Storage::disk('local')->download($path,$document->name);
    }
}
