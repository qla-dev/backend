<?php
namespace App\Services\Ops;

use App\Models\User;
use App\Services\OperationalCompliance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shipment contacts and document requests reuse the existing order context and participants. */
final class SupplyChain
{
    public function manages(object $order,User $user): bool {return app(OpsPortal::class)->buyer($order,$user)||app(OpsPortal::class)->provider($order,$user);}
    public function supplier(object $order,User $user): bool {return DB::table('ops_order_participants')->where('order_id',$order->id)->where('user_id',$user->id)->where('role','supplier')->where('status','active')->exists();}
    public function contacts(object $order): array {return app(OpsPortal::class)->context($order)['supply_chain']['contacts']??[];}
    private function save(object $order,array $contacts,User $user,string $event): void {
        $context=app(OpsPortal::class)->context($order);$context['supply_chain']['contacts']=array_values($contacts);
        DB::table('ops_operational_state')->where('order_id',$order->id)->update(['context'=>json_encode($context),'updated_at'=>now()]);DB::table('ops_operational_state')->where('order_id',$order->id)->increment('revision');
        app(OperationalCompliance::class)->audit((int)$order->company_id,'ops_order',(int)$order->id,(int)$user->id,$event,null,['contact_ids'=>array_column($contacts,'id')]);
    }
    public function read(object $order,User $user): array {
        $manage=$this->manages($order,$user);abort_unless($manage||$this->supplier($order,$user),403);
        $contacts=$this->contacts($order);if(!$manage)$contacts=array_values(array_filter($contacts,fn($c)=>(int)($c['user_id']??0)===(int)$user->id&&($c['status']??'')!=='revoked'));
        $docs=DB::table('ops_order_documents')->join('documents','documents.id','=','ops_order_documents.document_id')->where('order_id',$order->id)->get(['documents.id','documents.name','documents.uploaded_by_user_id','ops_order_documents.role']);
        $reviewed=app(OpsPortal::class)->documentState($order)['review_complete']?(app(OpsPortal::class)->context($order)['cargo_review']['document_ids']??[]):[];
        foreach($contacts as &$c){$participant=empty($c['user_id'])?null:DB::table('ops_order_participants')->where('order_id',$order->id)->where('user_id',$c['user_id'])->where('role','supplier')->first();$c['access']=$participant->status??'invited';$c['requirements']=array_map(function($role)use($docs,$c,$reviewed){$files=$docs->filter(fn($d)=>(int)$d->uploaded_by_user_id===(int)($c['user_id']??0)&&$d->role===$role)->values();return ['role'=>$role,'status'=>$files->contains(fn($d)=>in_array((int)$d->id,$reviewed,true))?'reviewed':($files->isNotEmpty()?'supplied':'requested'),'documents'=>$files->map(fn($d)=>['id'=>$d->id,'name'=>$d->name])->all()];},$c['required_documents']);}unset($c);
        return ['can_manage'=>$manage,'contacts'=>$contacts,'document_roles'=>['ci','pl','cmr','other']];
    }
    public function act(object $order,User $user,array $data): array {
        return DB::transaction(function()use($order,$user,$data){
            $order=app(OperationalCompliance::class)->order((int)$order->company_id,(int)$order->id,true);abort_if(in_array($order->status,['closed','cancelled'],true),422,'This order is read-only.');
            $contacts=$this->contacts($order);$manage=$this->manages($order,$user);
            if($data['action']==='invite'){
                abort_unless($manage,403);abort_if(count($contacts)>=30,422,'Maximum 30 shipment contacts.');$email=strtolower(trim($data['email']));$target=User::whereRaw('LOWER(email) = ?',[$email])->first();
                abort_if($target&&app(OpsPortal::class)->buyer($order,$target),422,'The buyer already has access and can supply documents directly.');
                abort_if(collect($contacts)->contains(fn($c)=>$c['email']===$email&&$c['status']!=='revoked'),422,'This contact is already invited.');
                $c=['id'=>(string)Str::uuid(),'revision'=>1,'company'=>$data['company'],'name'=>$data['name'],'email'=>$email,'phone'=>$data['phone']??'','role'=>$data['role'],'required_documents'=>$data['required_documents'],'due_date'=>$data['due_date']??null,'request_note'=>$data['request_note']??'','user_id'=>$target?->id,'invited_by'=>$user->id,'status'=>'invited','expires_at'=>now()->addDays(7)->toIso8601String(),'cargo_ready'=>false,'pickup_address'=>'','pickup_note'=>''];
                if($target)$this->bind($order,$c,$target);
                $contacts[]=$c;
            }else{
                $index=collect($contacts)->search(fn($c)=>$c['id']===$data['contact_id']);abort_if($index===false,404);$c=$contacts[$index];abort_unless((int)$c['revision']===$data['revision'],409,'The request changed. Refresh first.');
                if($data['action']==='revoke'){
                    abort_unless($manage,403);$c['status']='revoked';if($c['user_id'])DB::table('ops_order_participants')->where('order_id',$order->id)->where('user_id',$c['user_id'])->where('role','supplier')->update(['status'=>'revoked','revoked_at'=>now(),'updated_at'=>now(),'revision'=>DB::raw('revision + 1')]);
                }else{
                    abort_unless($manage||($this->supplier($order,$user)&&(int)$c['user_id']===(int)$user->id&&$c['status']!=='revoked'),403);
                    if($manage)foreach(['required_documents','due_date','request_note'] as $key)if(array_key_exists($key,$data))$c[$key]=$data[$key];
                    foreach(['cargo_ready','pickup_address','pickup_note','phone'] as $key)if(array_key_exists($key,$data))$c[$key]=$data[$key];
                }
                $c['revision']++;$contacts[$index]=$c;
            }
            $this->save($order,$contacts,$user,'supply_chain_'.$data['action']);return $this->read($order,$user);
        });
    }
    private function bind(object $order,array &$contact,User $target): void {
        $old=DB::table('ops_order_participants')->where('order_id',$order->id)->where('user_id',$target->id)->first();abort_if($old&&$old->role!=='supplier',422,'This person already has another role on the order.');
        $contact['user_id']=$target->id;
        DB::table('ops_order_participants')->updateOrInsert(['order_id'=>$order->id,'user_id'=>$target->id],['role'=>'supplier','status'=>'invited','revision'=>($old->revision??0)+1,'invited_by'=>$contact['invited_by'],'expires_at'=>\Carbon\Carbon::parse($contact['expires_at']),'accepted_at'=>null,'revoked_at'=>null,'created_at'=>$old->created_at??now(),'updated_at'=>now()]);
    }
    public function syncInvitations(User $user): void {
        if(!$user->email||!$user->email_verified_at)return;
        // Exact email matching below is authoritative; LIKE only narrows the context lookup.
        foreach(DB::table('ops_operational_state')->where('context','like','%'.strtolower($user->email).'%')->limit(200)->get(['order_id']) as $state){
            DB::transaction(function()use($user,$state){$order=DB::table('ops_orders')->where('id',$state->order_id)->lockForUpdate()->first();if(!$order||in_array($order->status,['closed','cancelled'],true))return;$contacts=$this->contacts($order);$changed=false;
                foreach($contacts as &$c)if(!$c['user_id']&&$c['email']===strtolower($user->email)&&$c['status']==='invited'&&\Carbon\Carbon::parse($c['expires_at'])->isFuture()){$this->bind($order,$c,$user);$changed=true;}unset($c);
                if($changed)$this->save($order,$contacts,$user,'supplier_identity_linked');});
        }
    }
    public function canUpload(object $order,User $user,string $role): bool {return $this->supplier($order,$user)&&collect($this->contacts($order))->contains(fn($c)=>(int)($c['user_id']??0)===(int)$user->id&&$c['status']!=='revoked'&&in_array($role,$c['required_documents'],true));}
    public function documentVisible(object $order,User $user,int $document): bool {return $this->supplier($order,$user)&&DB::table('ops_order_documents')->join('documents','documents.id','=','ops_order_documents.document_id')->where('order_id',$order->id)->where('document_id',$document)->where('uploaded_by_user_id',$user->id)->exists();}
}
