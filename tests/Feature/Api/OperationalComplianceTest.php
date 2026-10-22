<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use App\Services\OperationalCompliance;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationalComplianceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getConfig('driver'));
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        $this->assertSame('', DB::select('PRAGMA database_list')[0]->file);
        Schema::create('users', fn (Blueprint $t) => [$t->id(), $t->string('name')]);
        Schema::create('companies', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('owner_user_id'), $t->string('name')]);
        Schema::create('company_user', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('company_id'), $t->unsignedBigInteger('user_id'), $t->string('status')]);
        Schema::create('accounting_permissions', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('company_id'), $t->unsignedBigInteger('user_id'), $t->json('abilities')]);
        Schema::create('ops_orders', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('company_id'), $t->unsignedBigInteger('workspace_id')->nullable(), $t->unsignedBigInteger('load_id')->nullable(), $t->string('status'), $t->string('reference')]);
        Schema::create('documents', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('path')->nullable(); $t->unsignedBigInteger('uploaded_by_user_id')->nullable();
            foreach (['load_id','load_draft_id','vehicle_id','user_id'] as $key) $t->unsignedBigInteger($key)->nullable();
            $t->timestamps();
        });
        Schema::create('ops_order_documents', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('document_id')->nullable(), $t->string('role'), $t->timestamp('created_at')->nullable()]);
        (require database_path('migrations/2026_10_21_000001_create_operational_compliance.php'))->up();
        DB::table('users')->insert([['id'=>1,'name'=>'Owner'],['id'=>2,'name'=>'Other'],['id'=>3,'name'=>'Reviewer']]);
        DB::table('companies')->insert([['id'=>1,'owner_user_id'=>1,'name'=>'Demo'],['id'=>2,'owner_user_id'=>2,'name'=>'Other']]);
        DB::table('accounting_permissions')->insert(['company_id'=>1,'user_id'=>1,'abilities'=>json_encode(['ops','setup','view'])]);
        DB::table('ops_orders')->insert([['id'=>1,'company_id'=>1,'status'=>'open','reference'=>'TEST-1'],['id'=>2,'company_id'=>2,'status'=>'open','reference'=>'OTHER']]);
        $this->actor(1);
    }

    private function actor(int $id, string $role = 'company'): void
    {
        $user = new User(); $user->id = $id; $user->name = 'Test'; $user->setRelation('role', new Role(['name'=>$role]));
        Sanctum::actingAs($user);
    }

    private function state(): array
    {
        return $this->getJson('/api/accounting/1/ops/orders/1/compliance')->assertOk()->json('data.checklist');
    }

    private function update(array $items, ?array $state = null)
    {
        $state ??= $this->state();
        return $this->patchJson('/api/accounting/1/ops/orders/1/checklist', ['revision'=>$state['revision'], 'fingerprint'=>$state['fingerprint'], 'items'=>$items]);
    }

    public function test_company_isolation_and_no_permission_escalation(): void
    {
        $this->getJson('/api/accounting/1/ops/orders/2/compliance')->assertNotFound();
        $this->actor(2);
        $this->getJson('/api/accounting/1/ops/orders/1/compliance')->assertForbidden();
        $this->putJson('/api/accounting/1/country-profile', ['country_code'=>'HR','revision'=>0])->assertForbidden();
    }

    public function test_completion_is_server_attributed_and_stale_changes_rejected(): void
    {
        $state = $this->state();
        $this->update([['key'=>'booking','status'=>'completed','completed_by_user_id'=>999,'completed_at'=>'2000-01-01']], $state)->assertOk();
        $item = $this->state()['items'][0];
        $this->assertSame(1, $item['completed_by_user_id']);
        $this->assertStringNotContainsString('2000-', $item['completed_at']);
        $this->update([['key'=>'booking','status'=>'blocked','exception_reason'=>'Wait']], $state)->assertConflict();
        $this->assertSame(1, DB::table('compliance_audit')->where('entity','checklist')->count());
    }

    public function test_blocked_and_exception_require_reason_and_company_owner(): void
    {
        $this->update([['key'=>'booking','status'=>'blocked']])->assertUnprocessable();
        $this->update([['key'=>'booking','status'=>'pending','responsible_user_id'=>2]])->assertUnprocessable();
        $this->update([['key'=>'booking','status'=>'blocked','exception_reason'=>'Missing invoice','responsible_user_id'=>1]])->assertOk();
        $this->getJson('/api/accounting/1/ops/today')->assertOk()->assertJsonPath('data.0.order_id', 1);
    }

    public function test_context_change_invalidates_completed_checks_without_losing_audit(): void
    {
        $this->update([['key'=>'booking','status'=>'completed']])->assertOk();
        $state = $this->state();
        $this->patchJson('/api/accounting/1/ops/orders/1/checklist', ['revision'=>$state['revision'],'fingerprint'=>$state['fingerprint'],'context'=>['route'=>'Sarajevo-Zagreb']])->assertOk();
        $item = $this->state()['items'][0];
        $this->assertSame('pending', $item['status']); $this->assertTrue($item['needs_review']);
        $this->assertSame(1, $item['previous_completion']['by']);
    }

    public function test_templates_are_versioned_pinned_and_conditional(): void
    {
        $this->postJson('/api/accounting/1/ops/checklist-versions', ['items'=>[['key'=>'inspect','label'=>'Inspection','applies_when'=>['inspection_required']]]])->assertCreated();
        app(OperationalCompliance::class)->initialize(DB::table('ops_orders')->find(1));
        $this->postJson('/api/accounting/1/ops/checklist-versions', ['items'=>[['key'=>'changed','label'=>'New template']]])->assertCreated();
        $state = $this->state(); $this->assertSame(1, $state['template_version']);
        $this->assertFalse($state['items'][0]['applicable']);
        $this->update([['key'=>'inspect','status'=>'completed']])->assertUnprocessable();
        $this->postJson('/api/accounting/1/ops/checklist-versions', ['items'=>[['key'=>'cycle','label'=>'Cycle','depends_on'=>['cycle']]]])->assertUnprocessable();
    }

    public function test_inspection_draft_duplicate_official_evidence_and_cross_order_guards(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('documents/official.pdf', '%PDF-1.4 demo evidence');
        $draft = ['revision'=>0,'local_key'=>'a','control_type'=>'health','status'=>'draft','document_ids'=>[]];
        $response = $this->postJson('/api/accounting/1/ops/orders/1/inspections',$draft)->assertCreated();
        $this->postJson('/api/accounting/1/ops/orders/1/inspections',$draft)->assertConflict();
        $case = $response->json('data'); $case['status']='approved';
        $this->postJson('/api/accounting/1/ops/orders/1/inspections',$case)->assertUnprocessable();
        DB::table('documents')->insert(['id'=>1,'name'=>'Official decision','path'=>'official.pdf']);
        DB::table('ops_order_documents')->insert(['order_id'=>1,'document_id'=>1,'role'=>'attachment']);
        $case['external_reference']='BIMS-1'; $case['authority']='Inspection'; $case['document_ids']=[1];
        $this->postJson('/api/accounting/1/ops/orders/1/inspections',$case)->assertOk()->assertJsonPath('data.status','approved');
        $case['id']=999; $this->postJson('/api/accounting/1/ops/orders/1/inspections',$case)->assertNotFound();
        $this->assertSame('open', DB::table('ops_orders')->where('id',1)->value('status'));
    }

    public function test_country_pack_review_separation_expiry_and_permissions(): void
    {
        $this->putJson('/api/accounting/1/country-profile',['country_code'=>'HR','revision'=>0])->assertOk()->assertJsonPath('data.currency','EUR');
        $this->putJson('/api/accounting/1/country-profile',['country_code'=>'RS','revision'=>0])->assertConflict();
        $response = $this->postJson('/api/accounting/1/verifications',['country_code'=>'HR','capability'=>'carrier','activity'=>'international','document_ids'=>[],'registry_reference'=>'https://example.test/registry/1'])->assertCreated();
        $id = $response->json('data.id');
        $this->patchJson("/api/accounting/1/verifications/$id/review",['revision'=>1,'status'=>'in_review','reason'=>'Check register','verification_method'=>'registry'])->assertForbidden();
        $this->actor(3,'superadmin');
        $this->patchJson("/api/accounting/1/verifications/$id/review",['revision'=>1,'status'=>'verified','reason'=>'Checked','verification_method'=>'registry'])->assertUnprocessable();
        $this->patchJson("/api/accounting/1/verifications/$id/review",['revision'=>1,'status'=>'in_review','reason'=>'Check register','verification_method'=>'registry'])->assertOk();
        $this->patchJson("/api/accounting/1/verifications/$id/review",['revision'=>2,'status'=>'verified','reason'=>'Checked','verification_method'=>'registry'])->assertOk();
        DB::table('company_capability_verifications')->where('id',$id)->update(['expires_at'=>'2020-01-01']);
        $this->getJson('/api/accounting/1/country-profile')->assertOk()->assertJsonPath('data.verifications.0.effective_status','expired');
        $this->assertSame(['ops','setup','view'],json_decode(DB::table('accounting_permissions')->where('user_id',1)->value('abilities'),true));
    }

    public function test_closed_orders_and_unknown_checklist_items_are_immutable(): void
    {
        $this->update([['key'=>'invented','status'=>'completed']])->assertUnprocessable();
        DB::table('ops_orders')->where('id',1)->update(['status'=>'closed']);
        $this->update([['key'=>'booking','status'=>'completed']])->assertUnprocessable();
    }

    public function test_private_evidence_is_hidden_from_generic_documents_and_hash_checked(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('documents/evidence.pdf','test private evidence');
        DB::table('documents')->insert(['id'=>5,'name'=>'evidence.pdf','path'=>'evidence.pdf','uploaded_by_user_id'=>1]);
        $this->postJson('/api/accounting/1/verifications',['country_code'=>'BA','capability'=>'carrier','activity'=>'international','document_ids'=>[5]])->assertCreated();
        $this->assertNull(\App\Models\Document::find(5));
        $this->getJson('/api/documents/5/download')->assertNotFound();
        $this->get('/api/accounting/1/verification-documents/5')->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
        $this->actor(2); $this->get('/api/accounting/1/verification-documents/5')->assertForbidden();
        $this->actor(1); \Illuminate\Support\Facades\Storage::disk('local')->put('documents/evidence.pdf','changed');
        $this->getJson('/api/accounting/1/verification-documents/5')->assertConflict();
    }

    public function test_inspection_evidence_cannot_be_replaced_or_deleted(): void
    {
        DB::table('documents')->insert(['id'=>1,'name'=>'decision']);
        DB::table('ops_order_documents')->insert(['order_id'=>1,'document_id'=>1,'role'=>'attachment']);
        $this->postJson('/api/accounting/1/ops/orders/1/inspections',['revision'=>0,'local_key'=>'decision-1','control_type'=>'phyto','status'=>'draft','document_ids'=>[1]])->assertCreated();
        $this->putJson('/api/documents/1',['name'=>'changed'])->assertConflict();
        $this->deleteJson('/api/documents/1')->assertConflict();
    }

    public function test_inspection_condition_blocks_closing_without_an_approved_case(): void
    {
        $ops = app(OperationalCompliance::class); $order = DB::table('ops_orders')->find(1);
        $ops->initialize($order);
        $state = $this->state();
        $this->patchJson('/api/accounting/1/ops/orders/1/checklist',['revision'=>0,'fingerprint'=>$state['fingerprint'],'context'=>['inspection_required'=>true]])->assertOk();
        $this->update(array_map(fn ($i)=>['key'=>$i['key'],'status'=>'completed'],$state['items']))->assertOk();
        try { $ops->assertClosable($order); $this->fail('Missing inspection must block closing.'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('inspections',$e->errors()); }
        $this->assertSame('open',DB::table('ops_orders')->find(1)->status);
    }

    public function test_driver_verification_requires_a_member_and_does_not_change_roles(): void
    {
        $payload = ['country_code'=>'BA','capability'=>'driver','activity'=>'international','document_ids'=>[]];
        $this->postJson('/api/accounting/1/verifications',$payload)->assertUnprocessable();
        $this->postJson('/api/accounting/1/verifications',$payload+['subject_user_id'=>2])->assertUnprocessable();
        $this->postJson('/api/accounting/1/verifications',$payload+['subject_user_id'=>1])->assertCreated();
        $this->assertSame(1,DB::table('company_capability_verifications')->value('subject_user_id'));
    }

    public function test_iso_inspection_due_dates_appear_in_today_and_cancelled_orders_do_not(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 09:00:00'));
        $this->postJson('/api/accounting/1/ops/orders/1/inspections',['revision'=>0,'local_key'=>'due','control_type'=>'health','status'=>'draft','document_ids'=>[], 'due_at'=>'2026-10-21T14:00'])->assertCreated();
        $this->getJson('/api/accounting/1/ops/today')->assertOk()->assertJsonPath('data.0.kind','inspection');
        DB::table('ops_orders')->where('id',1)->update(['status'=>'cancelled']);
        $this->getJson('/api/accounting/1/ops/today')->assertOk()->assertJsonCount(0,'data');
    }
}
