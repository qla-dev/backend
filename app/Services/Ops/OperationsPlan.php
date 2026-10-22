<?php
namespace App\Services\Ops;

use App\Models\Load;
use App\Models\Offer;

/** One compact plan; optional work remains available and recorded work stays visible. */
final class OperationsPlan
{
    public const EXTRA = [
        'dangerous_goods_check'=>['Dangerous goods documentation and handling','dangerous_goods'],
        'temperature_check'=>['Temperature-control requirements','temperature_controlled'],
        'inspection_check'=>['Cargo inspection and certificates','inspection_required'],
        'customs_export'=>['Export customs clearance','export_required'],
        'customs_import'=>['Import customs clearance','import_required'],
        'transit'=>['Transit / NCTS','transit_required'],
        'sea_si_vgm'=>['Shipping instructions / VGM submission',null],
        'cargo_documents'=>['Receive and verify commercial invoice + packing list',null],
    ];

    public function booking(Load $load, Offer $offer, int $provider): array
    {
        $mode=$load->for_storage?'warehouse':$load->transport_type;
        $keys=match($mode){
            'sea'=>['booking_confirmation','cargo_documents','shipping_instructions','approve_draft','final_bill_of_lading','proof_of_delivery'],
            'air'=>['flight_details','cargo_documents','mawb_hawb','approve_awb','proof_of_delivery'],
            'rail'=>['rail_booking_confirmation','cargo_documents','arrival_and_release_documents','proof_of_delivery'],
            'warehouse'=>['check_storage_documents','record_storage_receipt','confirm_storage_dispatch'],
            default=>['assign_driver_and_vehicle','confirm_pickup_time','cmr_and_documents','proof_of_delivery','vehicle_return'],
        };
        if ($mode==='road' && ($load->customs_required || $load->requires_customs_bonded)) array_splice($keys,2,0,['cargo_documents']);
        $keys=array_values(array_diff($keys,['cargo_documents']));
        $items=[['key'=>'documents_received','label'=>'Prijem i provjera dokumentacije','status'=>'pending','plan_version'=>2,'controlled'=>true,'source'=>'document_intake','accepted_offer_id'=>$offer->id,'responsible_user_id'=>$provider,'audience'=>'provider']];
        foreach($keys as $key){
            $customer=in_array($key,OpsTaskAccess::CUSTOMER_KEYS,true);
            $driver=in_array($key,['cmr_and_documents','proof_of_delivery'],true)&&$mode==='road'?$offer->driver_user_id:null;
            $items[]=['key'=>$key,'status'=>'pending','plan_version'=>2,'source'=>'accepted_offer','accepted_offer_id'=>$offer->id,
                'responsible_user_id'=>$customer?$load->customer_user_id:($driver?:$provider),'audience'=>$customer?'customer':'provider',
                'controlled'=>$key==='cargo_documents','due_date'=>in_array($key,['proof_of_delivery','final_bill_of_lading','confirm_storage_dispatch'],true)?$offer->estimated_delivery_date:$offer->exact_loading_date,
                'action_value'=>null,'completed_at'=>null,'completed_by_user_id'=>null];
        }
        return $this->withRequirements($items,$load->only(['requires_adr','temperature_min','temperature_max','inspection_services_required','certification_required','customs_required','requires_customs_bonded']),$provider);
    }

    public function withRequirements(array $items,array $cargo,int $provider): array
    {
        $requirements=[];
        if(!empty($cargo['requires_adr']))$requirements[]='dangerous_goods_check';
        if(isset($cargo['temperature_min'])||isset($cargo['temperature_max']))$requirements[]='temperature_check';
        if(!empty($cargo['inspection_services_required'])||!empty($cargo['certification_required']))$requirements[]='inspection_check';
        if(!empty($cargo['customs_required']))$requirements[]='customs_import';
        if(!empty($cargo['requires_customs_bonded']))$requirements[]='transit';
        foreach($requirements as $key){
            if(collect($items)->contains('key',$key))continue;
            $items[]=['key'=>$key,'label'=>self::EXTRA[$key][0],'status'=>'pending','plan_version'=>2,'optional_selected'=>true,
                'requirement_source'=>'load_details','responsible_user_id'=>$provider,'audience'=>'provider'];
        }
        return $items;
    }

    public function project(array $items,string $mode,array $context=[]): array
    {
        $keys=array_column($items,'key');
        // Choose the established shipment task when both old systems generated the same work.
        $preferred=match($mode){
            'road'=>['assign_driver_and_vehicle','confirm_pickup_time','cmr_and_documents','proof_of_delivery','vehicle_return'],
            'sea'=>['booking_confirmation','shipping_instructions','approve_draft','final_bill_of_lading','proof_of_delivery'],
            'air'=>['flight_details','mawb_hawb','approve_awb','proof_of_delivery'],
            'rail'=>['rail_booking_confirmation','arrival_and_release_documents','proof_of_delivery'],
            'warehouse'=>['check_storage_documents','record_storage_receipt','confirm_storage_dispatch'],
            default=>[],
        };
        $core=array_intersect($preferred,$keys);
        if(!$core&&!collect($items)->contains(fn($item)=>!empty($item['generated'])))$core=$keys;
        if(!$core)$core=['booking','goods_documents','pickup','delivery',...match($mode){'road'=>['mode_carrier','mode_cmr'],'sea'=>['mode_final_bl_release'],'air'=>['mode_awb'],'rail'=>['mode_rail_note'],'warehouse'=>['mode_receipt','mode_dispatch'],default=>[]}];
        $hasIntake=in_array('documents_received',$keys,true);
        $core=[...$core,'documents_received','cargo_documents','cargo_reconciliation'];
        $known=['booking','goods_documents','pickup','transport','delivery','arrival','vehicle_return','vehicle_registrations','confirm_storage_arrival','assign_storage_location',
            'shipping_line_and_agent','container_details','vessel_and_voyage','approve_shipping_instructions','draft_bill_of_lading',
            'airline_and_agent','cargo_acceptance','security_and_customs_documents','draft_awb','rail_operator','terminals','wagon_or_container','departure_schedule','transit_status'];
        $projected=array_map(function($item)use($core,$known,$context,$hasIntake){
            $key=$item['key'];
            $extra=isset(self::EXTRA[$key]);
            $condition=self::EXTRA[$key][1]??null;
            $automatic=$condition&&!empty($context[$condition]);
            $started=in_array($item['status']??'pending',['in_progress','blocked','waiting_external','completed'],true)||!empty($item['action_value'])||!empty($item['document_ids']);
            $optional=!in_array($key,$core,true)&&($extra||in_array($key,$known,true)||str_starts_with($key,'mode_')||str_starts_with($key,'service_'));
            $item['optional']=$optional;
            $item['plan_active']=!$optional||$started||$automatic||!empty($item['optional_selected']);
            $item['applicable']=collect($item['applies_when']??[])->every(fn($flag)=>!empty($context[$flag]))&&$item['plan_active'];
            if($hasIntake&&$key==='goods_documents'&&!$started){$item['plan_active']=false;$item['applicable']=false;$item['optional']=false;}
            if($key==='cargo_documents'){$item['controlled']=true;if($hasIntake){$item['plan_active']=false;$item['applicable']=false;$item['optional']=false;}}
            return $item;
        },$items);
        usort($projected,fn($a,$b)=>(int)($b['key']==='documents_received')<=>(int)($a['key']==='documents_received'));
        return $projected;
    }
}
