<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\CompanyMembershipController;
use App\Models\User;
use App\Services\Accounting\AccountingAccess;
use App\Services\CompanyTeamAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Parent verifies the effective in-memory database before any fixture write. */
class CompanyTeamAccessTest extends AccountingModuleTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('roles', function(Blueprint $t) { $t->id(); $t->string('name'); $t->string('label')->default(''); $t->boolean('is_active')->default(true); });
        Schema::table('users', function(Blueprint $t) { $t->unsignedBigInteger('role_id')->nullable(); $t->string('email')->nullable(); });
        Schema::table('company_user', function(Blueprint $t) { $t->json('departments')->nullable(); $t->unsignedBigInteger('invited_by_user_id')->nullable(); $t->timestamp('joined_at')->nullable(); $t->timestamps(); });
        foreach(['company','manager','finance','user','dispatcher','superadmin','master','warehouse','driver','customs_officer'] as $i=>$name) DB::table('roles')->insert(['id'=>$i+1,'name'=>$name]);
        DB::table('users')->whereIn('id',[1,2])->update(['role_id'=>1]);
        DB::table('users')->insert([['id'=>3,'name'=>'Admin','role_id'=>6,'email'=>'admin@example.test'],['id'=>4,'name'=>'Staff','role_id'=>4,'email'=>'staff@example.test'],['id'=>5,'name'=>'Foreign manager','role_id'=>2,'email'=>'other@example.test']]);
        DB::table('company_user')->insert([['id'=>1,'company_id'=>1,'user_id'=>4,'status'=>'active','departments'=>'["road","accounting"]'],['id'=>2,'company_id'=>2,'user_id'=>4,'status'=>'active','departments'=>'["finance"]']]);
        (require database_path('migrations/2026_11_05_000001_scope_company_team_roles.php'))->up();
        DB::table('company_user')->where('id',1)->update(['company_role_id'=>2]);
        DB::table('company_user')->where('id',2)->update(['company_role_id'=>3]);
        Schema::create('company_invitations',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('role_id');$t->unsignedBigInteger('invited_by_user_id');$t->unsignedBigInteger('accepted_by_user_id')->nullable();$t->string('email');$t->string('token');$t->string('status');$t->timestamp('expires_at');$t->timestamp('accepted_at')->nullable();$t->timestamps();});
    }

    private function request(int $actor, array $data=[], int $company=1): Request
    {
        $request=Request::create('/accounting/'.$company.'/context','PATCH',$data);
        $request->setUserResolver(fn()=>User::findOrFail($actor));
        $route=new Route(['PATCH'],'accounting/{company}/context',fn()=>null); $route->bind($request);
        $request->setRouteResolver(fn()=>$route);
        return $request;
    }

    public function test_team_superadmin_lists_and_enters_accounting_and_ops_without_membership(): void
    {
        $admin=User::findOrFail(3);
        self::assertSame(0,DB::table('company_user')->where('user_id',3)->count());
        $response=app(AccountingController::class)->companies($this->request(3))->getData(true);
        self::assertCount(2,$response['data']);
        $context=app(AccountingController::class)->context($this->request(3))->getData(true)['data'];
        self::assertTrue($context['can_manage_permissions']); self::assertContains('ops',$context['abilities']); self::assertContains('view',$context['abilities']);
        app(AccountingAccess::class)->authorize($admin,2,'ops');
        app(AccountingAccess::class)->authorize($admin,2,'post');
    }

    public function test_team_master_has_same_administrative_company_access(): void
    {
        DB::table('users')->where('id',3)->update(['role_id'=>7]);
        self::assertSame(AccountingAccess::ABILITIES,app(AccountingAccess::class)->abilities(User::findOrFail(3),2));
    }

    public function test_team_admin_company_selector_prefers_their_connected_company(): void
    {
        DB::table('company_user')->insert(['company_id'=>2,'user_id'=>3,'status'=>'active']);
        $companies=app(AccountingController::class)->companies($this->request(3))->getData(true)['data'];
        self::assertSame([2,1],array_column($companies,'id'));
    }

    public function test_team_company_role_changes_do_not_change_account_or_other_company(): void
    {
        app(CompanyMembershipController::class)->update($this->request(1,['role_id'=>5]),1);
        self::assertSame(4,(int)DB::table('users')->where('id',4)->value('role_id'));
        $team=app(CompanyTeamAccess::class);$staff=User::findOrFail(4);
        self::assertSame('dispatcher',$team->role($staff,1));self::assertSame('finance',$team->role($staff,2));
        self::assertFalse($team->manages($staff,1));
        self::assertSame([],app(AccountingAccess::class)->abilities($staff,1));
    }

    public function test_team_finance_needs_no_transport_subdepartment_and_gets_no_implicit_abilities(): void
    {
        $data=app(CompanyMembershipController::class)->update($this->request(2,['business_departments'=>['finance'],'subdepartments'=>[]]),2)->getData(true)['data'];
        self::assertSame(['finance'],$data['business_departments']);self::assertSame([],$data['subdepartments']);
        self::assertSame([],app(AccountingAccess::class)->abilities(User::findOrFail(4),2));
    }

    public function test_team_legacy_organization_is_mapped_without_losing_transport_scope(): void
    {
        $member=DB::table('company_user')->where('id',1)->first();
        self::assertSame(['finance','operations'],json_decode($member->business_departments,true));
        self::assertSame(['road'],json_decode($member->subdepartments,true));
        self::assertSame('["road","accounting"]',$member->departments);
    }

    public function test_team_revoked_member_loses_even_explicit_accounting_rights(): void
    {
        DB::table('accounting_permissions')->insert(['company_id'=>2,'user_id'=>4,'granted_by'=>2,'abilities'=>'["view","ops"]']);
        app(CompanyMembershipController::class)->destroy($this->request(2),2);
        self::assertNull(app(CompanyTeamAccess::class)->role(User::findOrFail(4),2));
        $this->expectException(HttpException::class);app(AccountingAccess::class)->authorize(User::findOrFail(4),2,'ops');
    }

    public function test_team_manager_in_one_company_cannot_edit_membership_in_another(): void
    {
        self::assertTrue(app(CompanyTeamAccess::class)->manages(User::findOrFail(4),1));
        $this->expectException(HttpException::class);app(CompanyMembershipController::class)->update($this->request(4,['role_id'=>2]),2);
    }

    public function test_team_global_manager_without_membership_cannot_read_foreign_membership(): void
    {
        $this->expectException(HttpException::class);app(CompanyMembershipController::class)->show($this->request(5),1);
    }

    public function test_team_member_can_manage_only_their_company_when_role_is_manager(): void
    {
        self::assertTrue(app(CompanyTeamAccess::class)->manages(User::findOrFail(4),1));
        self::assertFalse(app(CompanyTeamAccess::class)->manages(User::findOrFail(4),2));
        self::assertSame('user',User::findOrFail(4)->role->name);
    }

    public function test_team_cannot_assign_platform_admin_role_from_company(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(CompanyMembershipController::class)->update($this->request(1,['role_id'=>6]),1);
    }

    public function test_team_transport_subdepartment_requires_operations_business_function(): void
    {
        $this->expectException(HttpException::class);
        app(CompanyMembershipController::class)->update($this->request(2,['business_departments'=>['finance'],'subdepartments'=>['road']]),2);
    }

    public function test_team_inactive_membership_does_not_leak_in_company_list(): void
    {
        DB::table('company_user')->where('id',2)->update(['status'=>'inactive']);
        self::assertSame([1],app(CompanyTeamAccess::class)->companies(User::findOrFail(4))->pluck('id')->all());
    }

    private function invitation(array $overrides=[]): string
    {
        $token=str_repeat('a',64);
        DB::table('company_invitations')->insert(array_merge(['company_id'=>2,'role_id'=>5,'invited_by_user_id'=>2,'email'=>'other@example.test','token'=>$token,'status'=>'pending','expires_at'=>now()->addDay()],$overrides));
        return $token;
    }

    public function test_team_invitation_acceptance_assigns_company_role_without_changing_account(): void
    {
        $token=$this->invitation();
        $controller=app(\App\Http\Controllers\Api\CompanyInvitationController::class);
        $controller->accept($this->request(5,['token'=>$token]));
        self::assertSame('dispatcher',app(CompanyTeamAccess::class)->role(User::findOrFail(5),2));
        self::assertSame('manager',User::findOrFail(5)->role->name);
        $controller->accept($this->request(5,['token'=>$token]));
        self::assertSame(1,DB::table('company_user')->where('company_id',2)->where('user_id',5)->count());
        self::assertSame([],app(AccountingAccess::class)->abilities(User::findOrFail(5),2));
    }

    public function test_team_invitation_cannot_be_accepted_by_another_email(): void
    {
        $token=$this->invitation();$this->expectException(HttpException::class);
        app(\App\Http\Controllers\Api\CompanyInvitationController::class)->accept($this->request(4,['token'=>$token]));
    }

    public function test_team_expired_invitation_does_not_grant_membership(): void
    {
        $token=$this->invitation(['expires_at'=>now()->subMinute()]);$this->expectException(HttpException::class);
        app(\App\Http\Controllers\Api\CompanyInvitationController::class)->accept($this->request(5,['token'=>$token]));
    }

    public function test_team_explicit_accounting_abilities_survive_company_role_change(): void
    {
        DB::table('accounting_permissions')->insert(['company_id'=>1,'user_id'=>4,'granted_by'=>1,'abilities'=>'["view","prepare"]']);
        app(CompanyMembershipController::class)->update($this->request(1,['role_id'=>3]),1);
        self::assertSame(['view','prepare'],app(AccountingAccess::class)->abilities(User::findOrFail(4),1));
        self::assertFalse(app(CompanyTeamAccess::class)->manages(User::findOrFail(4),1));
    }
}
