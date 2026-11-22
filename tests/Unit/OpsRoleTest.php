<?php
namespace Tests\Unit;

use App\Models\User;
use App\Services\OperationalCompliance;
use App\Services\Ops\OpsTaskAccess;
use App\Services\Ops\TransportFlow;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Inherits the fixture's read-only verification of SQLite :memory: before any write. */
class OpsRoleTest extends TransportFlowTest
{
    private function staff(): User
    {
        DB::table('users')->insertOrIgnore(['id'=>22,'name'=>'Operator']);
        DB::table('company_user')->insert(['company_id'=>1,'user_id'=>22,'status'=>'active']);
        $user=User::findOrFail(22);$user->setRelation('role',new \App\Models\Role(['name'=>'dispatcher']));
        return $user;
    }

    public function test_ux_staff_projection_excludes_other_people_and_unassigned_tasks(): void
    {
        $this->book();$staff=$this->staff();
        $projection=app(OpsTaskAccess::class)->project($staff,$this->order(),['items'=>[
            ['key'=>'own','responsible_user_id'=>22],['key'=>'other','responsible_user_id'=>1],['key'=>'unassigned'],['key'=>'delivery','system'=>true]]]);
        self::assertSame(['own','delivery'],array_column($projection['items'],'key'));
        self::assertTrue($projection['items'][0]['can_edit']);self::assertFalse($projection['items'][1]['can_edit']);
    }

    public function test_ux_staff_cannot_take_over_another_task_in_patch(): void
    {
        $this->book();$this->staff();$this->flow();
        $ops=app(OperationalCompliance::class);$state=$ops->read($this->order());
        try {$ops->update(1,$this->order()->id,22,['revision'=>$state['revision'],'fingerprint'=>$state['fingerprint'],'items'=>[['key'=>'booking','status'=>'in_progress','responsible_user_id'=>22]]]);self::fail('Must reject assignment takeover.');}
        catch(HttpException $e){self::assertSame(403,$e->getStatusCode());}
        self::assertSame($state['fingerprint'],$ops->read($this->order())['fingerprint']);
    }

    public function test_ux_assigned_staff_can_update_their_task_but_cannot_reassign_it(): void
    {
        $this->book();$staff=$this->staff();$this->flow();$ops=app(OperationalCompliance::class);
        $state=$ops->read($this->order());$state=$ops->update(1,$this->order()->id,1,['revision'=>$state['revision'],'fingerprint'=>$state['fingerprint'],'items'=>[['key'=>'booking','status'=>'pending','responsible_user_id'=>22]]]);
        $state=$ops->update(1,$this->order()->id,22,['revision'=>$state['revision'],'fingerprint'=>$state['fingerprint'],'items'=>[['key'=>'booking','status'=>'in_progress']]]);
        self::assertSame('in_progress',collect($state['items'])->firstWhere('key','booking')['status']);
        try {$ops->update(1,$this->order()->id,22,['revision'=>$state['revision'],'fingerprint'=>$state['fingerprint'],'items'=>[['key'=>'booking','status'=>'pending','responsible_user_id'=>1]]]);self::fail('Must reject delegation.');}
        catch(HttpException $e){self::assertSame(403,$e->getStatusCode());}
    }

    public function test_ux_customer_approval_cannot_be_confirmed_by_provider_owner(): void
    {
        $workspace=$this->book();$access=app(OpsTaskAccess::class);$owner=User::findOrFail(1);$owner->setRelation('role',new \App\Models\Role(['name'=>'company']));
        $buyer=new User(['name'=>'Buyer']);$buyer->id=$workspace->customer_user_id;$buyer->setRelation('role',new \App\Models\Role(['name'=>'user']));
        self::assertTrue($access->canEdit($buyer,$this->order(),['key'=>'approve_draft']));
        self::assertFalse($access->canEdit($owner,$this->order(),['key'=>'approve_draft']));
        self::assertFalse($access->canEdit($buyer,$this->order(),['key'=>'mode_shipping_line']));
    }

    public function test_sea_operations_are_independent_of_company_verification(): void
    {
        $this->book();
        $order = $this->order();
        $access = app(OpsTaskAccess::class);
        self::assertTrue($access->organizesSeaService($order));
        $legs = [['key'=>'sea','mode'=>'sea','from'=>'Nansha','to'=>'Rijeka'], ['key'=>'road','mode'=>'road','from'=>'Rijeka','to'=>'Sarajevo']];
        $flow = app(TransportFlow::class);
        self::assertCount(3, array_filter($flow->tasks('sea', $legs, 'FCL'), fn ($task) => !empty($task['sea_organizer_only'])));

        DB::table('shipment_workspace')->where('id', $order->workspace_id)->update(['provider_company_id' => 2]);
        self::assertFalse($access->organizesSeaService($order));
        self::assertCount(0, array_filter($flow->tasks('sea', $legs, 'FCL', false), fn ($task) => !empty($task['sea_organizer_only'])));
    }
}
