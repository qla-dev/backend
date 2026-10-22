<?php

namespace App\Services\Ops;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Assignment is independent of the permission to enter the company's OPS module. */
final class OpsTaskAccess
{
    public const CUSTOMER_KEYS = ['shipping_instructions', 'approve_draft', 'approve_awb'];

    public function manages(User $user, int $company): bool
    {
        return $user->isSuperAdminOrMaster()
            || (int) DB::table('companies')->where('id', $company)->value('owner_user_id') === (int) $user->id
            || in_array('setup', json_decode(DB::table('accounting_permissions')->where('company_id', $company)->where('user_id', $user->id)->value('abilities') ?? '[]', true), true);
    }

    public function verifiedSeaOrganizer(object $order): bool
    {
        if ($order->workspace_id && (int) DB::table('shipment_workspace')->where('id', $order->workspace_id)->value('provider_company_id') !== (int) $order->company_id) return false;
        if (Schema::hasTable('company_capability_verifications')) {
            // The latest decision for a capability supersedes any earlier approval.
            $records = DB::table('company_capability_verifications')->where('company_id', $order->company_id)
                ->whereNull('subject_user_id')->whereIn('capability', ['forwarder', 'overseas_agent'])->latest('id')->get()->unique('capability');
            if ($records->isNotEmpty()) return $records->contains(fn ($v) => $v->status === 'verified' && (!$v->expires_at || $v->expires_at >= now()->toDateString()));
        }
        return Schema::hasColumn('companies', 'verified_at') && DB::table('companies')->where('id', $order->company_id)->whereNotNull('verified_at')->exists();
    }

    public function canEdit(User $user, object $order, array $item): bool
    {
        if(!empty($item['provider_company_id'])&&(int)$item['provider_company_id']!==(int)$order->company_id)return false;
        if (!empty($item['controlled']) || !empty($item['system']) || ($item['applicable'] ?? true) === false) return false;
        if ((($item['audience'] ?? '') === 'customer' || in_array($item['key'] ?? '', self::CUSTOMER_KEYS, true)) && $order->workspace_id) {
            return (int) DB::table('shipment_workspace')->where('id', $order->workspace_id)->value('customer_user_id') === (int) $user->id || $user->isSuperAdminOrMaster();
        }
        if (!empty($item['verified_sea_only']) && !$this->verifiedSeaOrganizer($order)) return false;
        return $this->manages($user, (int) $order->company_id) || (int) ($item['responsible_user_id'] ?? 0) === (int) $user->id;
    }

    /** Exact task assignment wins over the workspace's general provider role. */
    public function visibleWorkspace(User $user, \App\Models\ShipmentWorkspace $workspace, array $items): array
    {
        if($user->isSuperAdminOrMaster())return $items;
        $customer=(int)$workspace->customer_user_id===(int)$user->id;
        $milestones=['booking_confirmation','final_bill_of_lading','arrival_and_release_documents','proof_of_delivery'];
        return array_values(array_filter($items,function($item)use($user,$workspace,$customer,$milestones){
            if(($item['applicable']??true)===false)return false;
            $buyer=($item['audience']??'')==='customer'||in_array($item['key']??'',self::CUSTOMER_KEYS,true);
            if($customer)return $buyer||in_array($item['key']??'',$milestones,true)||($item['key']??'')==='cargo_reconciliation';
            if($buyer)return false;
            $assigned=(int)($item['responsible_user_id']??0);
            if(!$assigned)$assigned=(int)$workspace->provider_user_id;
            return $assigned===(int)$user->id && (empty($item['provider_company_id']) || (int)$item['provider_company_id']===(int)$workspace->provider_company_id);
        }));
    }

    public function project(User $user, object $order, array $state): array
    {
        $manage = $this->manages($user, (int) $order->company_id);
        $state['can_manage'] = $manage;
        $state['viewer_id'] = (int) $user->id;
        $state['verified_sea_organizer'] = $this->verifiedSeaOrganizer($order);
        $state['items'] = array_values(array_filter(array_map(function ($item) use ($user, $order) {
            $item['can_edit'] = $this->canEdit($user, $order, $item);
            $item['audience'] = (($item['audience'] ?? '') === 'customer' || in_array($item['key'] ?? '', self::CUSTOMER_KEYS, true)) ? 'customer' : 'provider';
            return $item;
        }, $state['items']), fn ($item) => (empty($item['provider_company_id'])||(int)$item['provider_company_id']===(int)$order->company_id) && (!empty($item['system']) || (int) ($item['responsible_user_id'] ?? 0) === (int) $user->id || ($manage && empty($item['responsible_user_id'])))));
        return $state;
    }
}
