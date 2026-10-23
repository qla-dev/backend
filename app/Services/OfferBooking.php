<?php
namespace App\Services;

use App\Models\Load;
use App\Models\Offer;
use App\Models\ShipmentWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Acceptance, booking and the observer-created work order commit together. */
final class OfferBooking
{
    public function confirm(User $user,int $id,?int $driverId=null): array
    {
        return DB::transaction(function()use($user,$id,$driverId){
            $offer=Offer::query()->lockForUpdate()->findOrFail($id);
            $load=Load::query()->with('shipment')->lockForUpdate()->findOrFail($offer->load_id);
            abort_unless($user->isSuperAdminOrMaster()||(int)$load->customer_user_id===(int)$user->id,403,'Only the load owner can confirm this booking.');
            $existing=ShipmentWorkspace::where('load_id',$load->id)->lockForUpdate()->first();
            if($existing){abort_unless((int)$existing->accepted_offer_id===(int)$offer->id,409,'Another offer is already booked.');return [$offer,$existing];}
            abort_unless(in_array($offer->status,['pending','accepted'],true),409,'This offer is no longer available.');
            abort_unless(in_array($load->status,['posted','booked','sent'],true),409,'This load cannot be booked.');
            abort_if(Offer::where('load_id',$load->id)->whereKeyNot($id)->where('status','accepted')->exists(),409,'Conflicting accepted offers require review.');
            if($offer->status==='pending')abort_unless($load->status==='posted',409,'This load is no longer open for offers.');
            $driverId=$driverId?:$offer->driver_user_id;
            if($driverId&&!User::whereKey($driverId)->whereHas('role',fn($q)=>$q->where('name','driver'))->exists())throw ValidationException::withMessages(['driver_user_id'=>'Select a user with the driver role.']);
            Offer::where('load_id',$load->id)->whereKeyNot($id)->where('status','pending')->update(['status'=>'not_selected']);
            $offer->update(['driver_user_id'=>$driverId,'status'=>'accepted']);
            app(WarehouseMovementCreator::class)->create($load,$offer);
            $workspace=app(ShipmentWorkspaceCreator::class)->create($load,$offer->fresh(),$user);
            $load->update(['assigned_driver_user_id'=>$driverId,'company_id'=>$offer->company_id??$load->company_id,'status'=>'booked','pre_delivery_status'=>null,'booking_status'=>'confirmed','booking_reference'=>$workspace->reference]);
            return [$offer,$workspace];
        });
    }

    public function repairMine(User $user): int
    {
        $count=0;
        foreach(Offer::where('status','accepted')->whereHas('freightLoad',fn($q)=>$q->where('customer_user_id',$user->id)->whereIn('status',['posted','booked','sent']))->whereDoesntHave('freightLoad.shipmentWorkspace')->orderBy('id')->limit(100)->get() as $offer){
            try{$this->confirm($user,(int)$offer->id);$count++;}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==409)throw $e;}
        }
        return $count;
    }
}
