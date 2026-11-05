<?php
namespace Tests\Unit;

use App\Models\Role;
use App\Models\User;
use App\Http\Controllers\Api\OpsController;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;

class CompanyTeamOpsAdminTest extends TransportFlowTest
{
    public function test_company_superadmin_ops_overview_works_without_membership(): void
    {
        $this->book();
        $admin=User::findOrFail(2);$admin->setRelation('role',new Role(['name'=>'superadmin']));
        self::assertSame(0,DB::table('company_user')->where('user_id',2)->count());
        $r=Request::create('/accounting/1/ops');$r->setUserResolver(fn()=>$admin);
        $route=new Route(['GET'],'accounting/{company}/ops',fn()=>null);$route->bind($r);$r->setRouteResolver(fn()=>$route);
        $data=app(OpsController::class)->overview($r)->getData(true)['data'];
        self::assertCount(1,$data['orders']);
        self::assertSame(1,(int)$data['orders'][0]['company_id']);
    }
}
