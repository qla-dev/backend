<?php
namespace App\Services\Crm;
use App\Models\User;
use App\Services\Accounting\AccountingAccess;
use Illuminate\Support\Facades\{DB,Schema};
final class CrmLoadLink {
    /** Must run in the same transaction that inserts the load and updates crm_documents.load_id. */
    public function assertAvailable(User $user,int $company,int $document,?int $version):object {
        if(DB::transactionLevel()===0)throw new \LogicException('CRM conversion requires a transaction.');
        app(AccountingAccess::class)->authorize($user,$company,'crm');
        $crm=DB::table('crm_documents')->where(['id'=>$document,'company_id'=>$company])->lockForUpdate()->first();
        abort_unless($crm,422,'CRM document does not belong to this company.');
        abort_if($crm->load_id,409,'This CRM document already has transport job #'.$crm->load_id.'. Open the existing job.');
        if(Schema::hasTable('crm_logistics_cases')) {
            $case=DB::table('crm_logistics_cases')->where('crm_document_id',$document)->first();
            if($case)abort_unless(in_array($crm->stage,['lead','offer','order'])&&$case->accepted_version_id&&(int)$case->accepted_version_id===(int)$version,409,'Accept the current customer quote version before opening a transport job.');
        }
        return $crm;
    }
}
