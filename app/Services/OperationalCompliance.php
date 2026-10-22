<?php

namespace App\Services;

use App\Models\ShipmentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Metadata augments the existing shipment checklist; it never creates a second copy for booked shipments. */
final class OperationalCompliance
{
    public const STATUSES = ['pending', 'in_progress', 'waiting_external', 'blocked', 'completed', 'not_applicable'];

    public function defaults(): array
    {
        return array_map(fn ($key, $label) => ['key' => $key, 'label' => $label, 'status' => 'pending'],
            ['booking','goods_documents','pickup','arrival','delivery'],
            ['Potvrda posla','Roba i dokumenti','Preuzimanje','Dolazak','Isporuka / POD']);
    }

    public function initialize(object $order): void
    {
        $template = $this->templateFor($order);
        DB::table('ops_operational_state')->insertOrIgnore(['order_id' => $order->id, 'company_id' => $order->company_id,
            'revision' => 0, 'template_id' => $template?->id, 'items' => $order->workspace_id ? null : ($template?->items ?? json_encode($this->defaults())),
            'context' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function templateFor(object $order): ?object
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('ops_checklist_versions','transport_type')) return DB::table('ops_checklist_versions')->where('company_id',$order->company_id)->latest('version')->first();
        $flow = \Illuminate\Support\Facades\Schema::hasTable('ops_transport_flows') ? DB::table('ops_transport_flows')->where('order_id',$order->id)->first() : null;
        $mode = $flow?->transport_type ?? ($order->load_id ? DB::table('loads')->where('id',$order->load_id)->value('transport_type') : null) ?? (($order->template_id ?? null) ? DB::table('ops_service_templates')->where('id',$order->template_id)->value('transport_type') : null);
        $variant = $flow?->variant;
        // Unknown manual orders receive generic tasks, never another mode's most recent template.
        return DB::table('ops_checklist_versions')->where('company_id',$order->company_id)->where('transport_type',$mode)->where('variant',$variant)->latest('version')->first()
            ?? DB::table('ops_checklist_versions')->where('company_id',$order->company_id)->whereNull('transport_type')->whereNull('variant')->latest('version')->first();
    }

    public function blockers(object $order): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('ops_operational_state')) return [];
        try { $this->assertClosable($order); return []; }
        catch (ValidationException $e) { return array_keys($e->errors()); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { if($e->getStatusCode()===409)return ['cargo_review'];throw $e; }
    }

    public function assertClosable(object $order): void
    {
        // Existing jobs opt in when their checklist is first edited. Do not retroactively block
        // historic orders merely because the migration was installed.
        if (!DB::table('ops_operational_state')->where('order_id', $order->id)->exists()) return;
        $state = $this->read($order, false);
        app(\App\Services\Ops\CargoReview::class)->assertUnblocked($order->load_id);
        foreach ($state['items'] as $item) {
            if (($item['key'] ?? '') === 'financial_review') continue;
            if (empty($item['system']) && ($item['applicable'] ?? true) && !in_array($item['status'] ?? '', ['completed','not_applicable'], true)) {
                throw ValidationException::withMessages(['checklist' => 'Prije zatvaranja završite primjenjive operativne provjere.']);
            }
        }
        if ($state['context']['inspection_required'] ?? false) {
            $cases = DB::table('ops_inspection_cases')->where('company_id', $order->company_id)->where('order_id', $order->id)->get();
            if ($cases->isEmpty() || $cases->contains(fn ($c) => $c->status !== 'approved')) {
                throw ValidationException::withMessages(['inspections' => 'Zahtijevana inspekcija još nema potpunu odobrenu odluku.']);
            }
            foreach ($cases as $case) {
                $ids = json_decode($case->document_ids, true);
                $hashes = $this->documentHashes($ids);
                if (count($hashes) !== count($ids) || $hashes !== json_decode($case->document_hashes ?? '{}', true)) {
                    throw ValidationException::withMessages(['inspections' => 'Inspekcijski dokaz je nedostupan ili izmijenjen.']);
                }
            }
        }
    }

    public function documentHashes(array $ids): array
    {
        $hashes = [];
        foreach ($ids as $id) {
            $doc = \App\Models\Document::withoutGlobalScopes()->find($id);
            if (!$doc || !$doc->path) continue;
            $path = 'documents/'.$doc->path;
            if (\Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
                $hashes[(string) $id] = hash_file('sha256', \Illuminate\Support\Facades\Storage::disk('local')->path($path));
            }
        }
        ksort($hashes);
        return $hashes;
    }

    public function audit(int $company, string $entity, int $id, int $actor, string $action, mixed $before, mixed $after): void
    {
        DB::table('compliance_audit')->insert(['company_id' => $company, 'entity' => $entity, 'entity_id' => $id,
            'actor_id' => $actor, 'action' => $action, 'before' => $before === null ? null : json_encode($before),
            'after' => $after === null ? null : json_encode($after), 'created_at' => now()]);
    }

    public function member(int $company, ?int $user): void
    {
        if (!$user) return;
        abort_unless(DB::table('companies')->where('id', $company)->where('owner_user_id', $user)->exists()
            || DB::table('company_user')->where('company_id', $company)->where('user_id', $user)->where('status', 'active')->exists(), 422, 'Odgovorna osoba mora biti aktivni član kompanije.');
    }

    public function order(int $company, int $id, bool $lock = false): object
    {
        return DB::table('ops_orders')->where('company_id', $company)->where('id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->first() ?? abort(404);
    }

    public function read(object $order, bool $deriveSystem = true): array
    {
        $state = DB::table('ops_operational_state')->where('order_id', $order->id)->first();
        $workspace = $order->workspace_id ? ShipmentWorkspace::findOrFail($order->workspace_id) : null;
        $template = $state ? ($state->template_id ? DB::table('ops_checklist_versions')->find($state->template_id) : null)
            : $this->templateFor($order);
        $context = json_decode($state?->context ?? '{}', true);
        $items = $workspace ? $workspace->operational_checklist : json_decode($state?->items ?? $template?->items ?? json_encode($this->defaults()), true);
        $fingerprint = hash('sha256', json_encode($items));
        $settlement = $deriveSystem && \Illuminate\Support\Facades\Schema::hasTable('ops_transport_flows') && DB::table('ops_transport_flows')->where('order_id', $order->id)->exists() ? app(\App\Services\Ops\TransportSettlement::class)->read($order) : null;
        $items = array_map(function ($item) use ($context, $settlement) {
            if (($item['status'] ?? '') === 'completed' && !empty($item['required_documents'])) {
                $hashes = $this->documentHashes($item['document_ids'] ?? []);
                if (empty($item['document_ids']) || $hashes !== ($item['document_hashes'] ?? [])) { $item['status']='pending'; $item['needs_review']=true; }
            }
            if (!empty($item['system']) && $settlement) $item['status'] = ($item['key'] === 'delivery' ? $settlement['transport'] === 'delivered' : $settlement['payment'] === 'paid') ? 'completed' : 'pending';
            $item['applicable'] = collect($item['applies_when'] ?? [])->every(fn ($condition) => ($context[$condition] ?? false) === true);
            return $item;
        }, $items);
        if($workspace)foreach($items as &$item){
            if(empty($item['responsible_user_id'])&&($item['assignment_source']??'')!=='manual'){
                $customer=in_array($item['key'],\App\Services\Ops\OpsTaskAccess::CUSTOMER_KEYS,true)||($item['audience']??'')==='customer';
                $item['responsible_user_id']=$customer?$workspace->customer_user_id:$workspace->provider_user_id;
                $item['assignment_source']='accepted_offer';
            }
        }unset($item);
        return ['revision' => (int) ($state?->revision ?? 0), 'fingerprint' => $fingerprint, 'template_version' => $template?->version ?? 'builtin-1',
            'source' => $workspace ? 'shipment_workspace' : 'ops_order', 'context' => $context,
            'items' => app(\App\Services\Ops\OperationsPlan::class)->project($items, \Illuminate\Support\Facades\Schema::hasTable('ops_transport_flows') ? (DB::table('ops_transport_flows')->where('order_id',$order->id)->value('transport_type') ?? ($workspace?->load_snapshot['transport_type'] ?? 'road')) : ($workspace?->load_snapshot['transport_type'] ?? 'road'), $context), 'workspace_id' => $order->workspace_id];
    }

    public function update(int $company, int $id, int $actor, array $data): array
    {
        return DB::transaction(function () use ($company, $id, $actor, $data) {
            $order = $this->order($company, $id, true);
            if ($order->workspace_id) ShipmentWorkspace::whereKey($order->workspace_id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($order->status, ['closed', 'cancelled'], true), 422, 'Zatvoren ili otkazan nalog je samo za čitanje.');
            $before = $this->read($order);
            abort_unless($data['revision'] === $before['revision'], 409, 'Podaci su izmijenjeni. Osvježite nalog.');
            abort_unless(hash_equals($before['fingerprint'], $data['fingerprint']), 409, 'Shipment checklista je izmijenjena. Osvježite nalog.');
            $items = collect($before['items'])->keyBy('key');
            $context = array_replace($before['context'], $data['context'] ?? []);
            $user = \App\Models\User::findOrFail($actor);
            $access = app(\App\Services\Ops\OpsTaskAccess::class);
            abort_if($context !== $before['context'] && !$access->manages($user, $company), 403, 'Samo voditelj operative mijenja uslove naloga.');
            if ($context !== $before['context']) {
                $items = $items->map(function ($item) {
                    if (($item['status'] ?? '') === 'completed') {
                        $item['status'] = 'pending'; $item['needs_review'] = true;
                        $item['previous_completion'] = ['at' => $item['completed_at'] ?? null, 'by' => $item['completed_by_user_id'] ?? null];
                        $item['completed_at'] = null; $item['completed_by_user_id'] = null;
                    }
                    return $item;
                });
            }
            foreach ($data['items'] ?? [] as $update) {
                if(in_array($update['status']??'pending',['in_progress','completed','waiting_external'],true))app(\App\Services\Ops\OpsOrders::class)->assertConfirmed($order);
                $key = $update['key'];
                abort_unless($items->has($key), 422, 'Nepoznata stavka checkliste.');
                $old = $items[$key];
                abort_if(!empty($old['controlled']),422,'Use the cargo / price amendment workflow for this task.');
                if (($update['status']??'')==='completed') app(\App\Services\Ops\CargoReview::class)->assertUnblocked($order->load_id);
                // Validate against stored assignment, never the assignment supplied by the caller.
                if (empty($old['system']) && ($old['applicable'] ?? true)) abort_unless($access->canEdit($user, $order, $old), 403, 'Možete uređivati samo vlastita zaduženja.');
                abort_if(!$access->manages($user, $company) && (int) ($update['responsible_user_id'] ?? $old['responsible_user_id'] ?? 0) !== (int) ($old['responsible_user_id'] ?? 0), 403, 'Zaduženja dodjeljuje voditelj operative.');
                abort_if(!empty($old['system']), 422, 'Ovu stavku potvrđuje isporuka ili postojeće računovodstvo.');
                abort_unless(collect($old['applies_when'] ?? [])->every(fn ($condition) => ($context[$condition] ?? false) === true), 422, 'Stavka nije primjenjiva u ovom kontekstu.');
                $item = array_merge($old, $update);
                if(array_key_exists('responsible_user_id',$update))$item['assignment_source']='manual';
                $this->member($company, $item['responsible_user_id'] ?? null);
                $item['updated_by'] = $actor; $item['updated_at'] = now()->toIso8601String();
                if (in_array($item['status'], ['blocked', 'not_applicable'], true)) {
                    abort_if(empty($item['exception_reason']), 422, 'Potreban je razlog blokade ili izuzetka.');
                }
                if ($item['status'] === 'not_applicable') {
                    abort_if($order->workspace_id && ChecklistStatusRequirements::category($old), 422, 'Obavezna shipment stavka ne može se isključiti preko naloga.');
                    $item['exception_approved_by'] = $actor;
                }
                if ($item['status'] === 'completed' && ($old['status'] ?? '') !== 'completed') {
                    if (!empty($item['required_documents'])) {
                        abort_if(empty($item['document_ids']), 422, 'Priložite potrebni dokument kao dokaz.');
                        app(\App\Services\Ops\TransportFlow::class)->documents($order, $item['document_ids']);
                        $item['document_hashes'] = $this->documentHashes($item['document_ids']);
                    }
                    if ($order->workspace_id) {
                        $workspace = ShipmentWorkspace::findOrFail($order->workspace_id);
                        ChecklistStatusRequirements::assertTaskAllowed($workspace->freightLoad, $old);
                    }
                    foreach ($item['depends_on'] ?? [] as $dependency) {
                        abort_unless(in_array($items[$dependency]['status'] ?? '', ['completed', 'not_applicable'], true), 422, 'Prvo završite zavisne stavke.');
                    }
                    $item['completed_at'] = now()->toIso8601String(); $item['completed_by_user_id'] = $actor;
                    $item['confirmation_source'] = 'human'; $item['needs_review'] = false;
                } elseif ($item['status'] !== 'completed') {
                    $item['completed_at'] = null; $item['completed_by_user_id'] = null;
                }
                $items[$key] = $item;
            }
            $values = app(\App\Services\Ops\OperationsPlan::class)->withRequirements($items->values()->all(),['requires_adr'=>$context['dangerous_goods']??false,'temperature_min'=>!empty($context['temperature_controlled'])?0:null],$order->responsible_user_id??$actor);
            if ($order->workspace_id) ShipmentWorkspace::findOrFail($order->workspace_id)->update(['operational_checklist' => $values]);
            $template = DB::table('ops_checklist_versions')->where('company_id', $company)->latest('version')->first();
            $oldState = DB::table('ops_operational_state')->where('order_id', $id)->first();
            DB::table('ops_operational_state')->updateOrInsert(['order_id' => $id], ['company_id' => $company,
                'revision' => $before['revision'] + 1, 'template_id' => $oldState?->template_id ?? $template?->id,
                'items' => $order->workspace_id ? null : json_encode($values), 'context' => json_encode($context),
                'created_at' => $oldState?->created_at ?? now(), 'updated_at' => now()]);
            $after = $this->read($order);
            $this->audit($company, 'checklist', $id, $actor, 'updated', $before, $after);
            return $after;
        });
    }
}
