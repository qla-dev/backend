<?php

namespace App\Services\Ops;

use App\Services\OperationalCompliance;
use Illuminate\Support\Facades\DB;

/** Transport configuration, with tasks stored in the existing canonical checklist. */
final class TransportFlow
{
    public const MODES = ['road','sea','air','rail','multimodal','warehouse','customs'];
    public const PHASES = ['booking'=>'Booking', 'goods_documents'=>'Roba i dokumentacija', 'pickup'=>'Organizacija preuzimanja',
        'customs_export'=>'Izvozna carina', 'transport'=>'Transport', 'transit'=>'Tranzit / T1', 'customs_import'=>'Uvozna carina', 'delivery'=>'Finalna dostava / POD'];

    public function read(object $order): array
    {
        $flow = DB::table('ops_transport_flows')->where('company_id', $order->company_id)->where('order_id', $order->id)->first();
        $mode = $flow?->transport_type ?? ($order->load_id ? DB::table('loads')->where('id', $order->load_id)->value('transport_type') : null) ?? (app(OpsOrders::class)->intake($order)['department'] ?: 'road');
        $state=DB::table('ops_operational_state')->where('order_id',$order->id)->first();
        $items=$order->workspace_id ? (\App\Models\ShipmentWorkspace::find($order->workspace_id)?->operational_checklist ?? []) : json_decode($state?->items ?? '[]',true);
        $legs=json_decode($flow?->legs ?? '[]',true);
        foreach ($legs as &$leg) {
            $task=collect($items)->firstWhere('leg_key',$leg['key']);
            if ($task) { $leg['responsible_user_id']=$task['responsible_user_id'] ?? null; $leg['due_date']=$task['due_date'] ?? null; }
        }
        unset($leg);
        return ['revision' => (int) ($flow?->revision ?? 0), 'transport_type' => $mode, 'variant' => $flow?->variant,
            'legs' => $legs, 'transit' => json_decode($flow?->transit ?? '[]', true),
            'phases' => self::PHASES, 'modes' => self::MODES, 'active' => $flow !== null, 'checklist_revision' => (int) DB::table('ops_operational_state')->where('order_id',$order->id)->value('revision')];
    }

    public function tasks(string $mode, array $legs, ?string $variant = null, bool $verifiedSea = false): array
    {
        $items = [];
        foreach (self::PHASES as $key => $label) {
            $items[] = ['key' => $key, 'label' => $label, 'phase' => $key, 'status' => 'pending',
                'applies_when' => match ($key) { 'customs_export' => ['export_required'], 'customs_import' => ['import_required'], 'transit' => ['transit_required'], default => [] },
                'system' => in_array($key, ['delivery','financial_review']), 'generated' => true];
        }
        $modeTasks = match ($mode) {
            'road' => [['carrier','Prijevoznik, vozilo i vozač','booking',[]],['pickup_slot','Termin preuzimanja','pickup',[]],['cmr','Tovarni list / CMR','transport',['CMR ili odgovarajući tovarni list']]],
            'sea' => [['shipping_line','Brodar i agent','booking',[]],['vessel','Brod i plovidba','booking',[]],['cutoff','Rokovi predaje robe i dokumenata','pickup',[]],['shipping_instructions','Shipping instructions','goods_documents',['Shipping instructions']],['draft_bl','Pregled nacrta B/L','goods_documents',['Nacrt B/L']],['final_bl_release','Finalni B/L i release','delivery',['B/L i potvrda releasea']]],
            'air' => [['flight','Avioprijevoznik, let i booking','booking',[]],['cargo_acceptance','Prihvat robe i sigurnosna provjera','pickup',[]],['awb','AWB / MAWB / HAWB','goods_documents',['Primjenjivi avionski tovarni list']]],
            'rail' => [['rail_operator','Operater, terminal i raspored','booking',[]],['wagon','Vagon / kontejner','pickup',[]],['rail_note','CIM / SMGS prema ruti','goods_documents',['Primjenjivi željeznički tovarni list']]],
            'customs' => [['declaration','Carinska deklaracija','goods_documents',['Carinska deklaracija']],['customs_release','Carinsko puštanje','delivery',['Potvrda carinskog puštanja']]],
            'warehouse' => [['arrival_notice','Najava skladištu','booking',[]],['receipt','Prijem robe','pickup',['Prijemnica']],['location','Skladišna lokacija','transport',[]],['dispatch','Otprema i primopredaja','delivery',['Otpremnica']]],
            default => [],
        };
        if ($variant === 'LTL' || $variant === 'LCL') $modeTasks[]=['consolidation','Konsolidacija i primopredaja zbirnog tereta','pickup',[]];
        if (in_array($mode,['sea','rail'],true) && $variant === 'FCL') $modeTasks[]=['container','Kontejner i primjenjivi VGM','pickup',[]];
        foreach ($modeTasks as [$key,$label,$phase,$required]) $items[]=['key'=>'mode_'.$key,'label'=>$label,'phase'=>$phase,'status'=>'pending','generated'=>true,'required_documents'=>$required];
        $documents = ['road' => ['CMR / tovarni list'], 'sea' => ['Booking potvrda','B/L i release'], 'air' => ['AWB / MAWB / HAWB'],
            'rail' => ['CIM / SMGS prema ruti'], 'warehouse' => ['Prijemnica / otpremnica'], 'customs' => ['Carinska deklaracija i puštanje']];
        if (!$legs) $legs = [['key'=>'main', 'mode'=>$mode, 'from'=>'', 'to'=>'']];
        foreach ($legs as $leg) {
            $items[] = ['key'=>'leg_'.$leg['key'], 'label'=>($leg['from'] ?: 'Polazište').' → '.($leg['to'] ?: 'Odredište'), 'phase'=>'transport',
                'leg_key'=>$leg['key'], 'status'=>'pending', 'generated'=>true, 'required_documents'=>$documents[$leg['mode']] ?? ['Dokaz primopredaje'],
                'responsible_user_id'=>$leg['responsible_user_id'] ?? null, 'due_date'=>$leg['due_date'] ?? null, 'next_action'=>'Potvrditi preuzimanje, dionicu i dokumentaciju'];
        }
        foreach ($legs as $leg) {
            foreach ($this->serviceTasks($leg, $verifiedSea) as $task) $items[] = $task;
        }
        return array_map(fn($item)=>$item+['plan_version'=>2],$items);
    }

    private function serviceTasks(array $leg, bool $verifiedSea): array
    {
        $definitions = match ($leg['mode']) {
            'road' => [['carrier','Prijevoznik i vozilo',[]], ['cmr','CMR',['CMR']]],
            'sea' => [['shipping_line','Brodar i plovidba',[]], ['bl','B/L i release',['B/L i release']]],
            'rail' => [['operator','Operater i terminal',[]], ['rail_note','CIM / SMGS',['CIM / SMGS']]],
            'air' => [['flight','Let i booking',[]], ['awb','AWB',['AWB']]],
            'warehouse' => [['receipt','Prijem robe',['Prijemnica']], ['dispatch','Otprema robe',['Otpremnica']]],
            'customs' => [['declaration','Deklaracija',['Carinska deklaracija']], ['release','Puštanje',['Carinsko puštanje']]],
            default => [],
        };
        if ($leg['mode'] === 'sea' && $verifiedSea) {
            $definitions = [...$definitions,
                ['booking_cutoff', 'Booking i terminalski cut-off', []],
                ['si_vgm', 'Predaja SI i primjenjivog VGM', ['SI / VGM potvrda']],
                ['arrival_release', 'Najava dolaska i preuzimanje kontejnera', ['Arrival notice / delivery order']]];
        }
        return array_map(fn($d) => ['verified_sea_only'=>in_array($d[0], ['booking_cutoff','si_vgm','arrival_release'], true), 'key'=>'service_'.$leg['key'].'_'.$d[0], 'leg_key'=>$leg['key'], 'label'=>$d[1].' · '.$leg['from'].' → '.$leg['to'], 'phase'=>'transport', 'status'=>'pending', 'generated'=>true, 'required_documents'=>$d[2], 'responsible_user_id'=>$leg['responsible_user_id'] ?? null, 'due_date'=>$leg['due_date'] ?? null], $definitions);
    }

    public function validateVariant(string $mode, ?string $variant): void
    {
        abort_if($variant && !in_array($variant, match($mode) { 'road'=>['FTL','LTL'], 'sea','rail'=>['FCL','LCL'], default=>[] }, true),422,'Varijanta ne odgovara vrsti usluge.');
    }

    public function save(object $order, int $actor, array $data): array
    {
        return DB::transaction(function () use ($order, $actor, $data) {
            $ops = app(OperationalCompliance::class);
            $order = $ops->order($order->company_id, $order->id, true);
            abort_if($order->status === 'cancelled', 422, 'Otkazan nalog je samo za čitanje.');
            if(collect($data['legs'])->contains(fn($leg)=>($leg['status']??'planned')!=='planned')){app(OpsOrders::class)->assertConfirmed($order);app(OpsPortal::class)->assertDocumentsReady($order);}
            $this->validateVariant($data['transport_type'], $data['variant'] ?? null);
            foreach ($data['legs'] as $leg) $this->validateVariant($leg['mode'], $leg['variant'] ?? null);
            $before = $this->read($order);
            if (collect($data['legs'])->contains(fn($leg)=>($leg['status']??'planned')!=='planned')) app(CargoReview::class)->assertUnblocked($order->load_id);
            $actorUser = \App\Models\User::findOrFail($actor);
            if (!app(OpsTaskAccess::class)->manages($actorUser, (int) $order->company_id)) {
                abort_unless($before['transport_type'] === $data['transport_type'] && $before['variant'] === ($data['variant'] ?? null), 403);
                foreach (['legs','transit'] as $group) {
                    $previous = collect($before[$group])->keyBy('key');
                    abort_unless(array_column($before[$group], 'key') === array_column($data[$group], 'key'), 403);
                    foreach ($data[$group] as $entry) {
                        $oldEntry = $previous[$entry['key']]; unset($oldEntry['document_hashes']);
                        if ($oldEntry == $entry) continue;
                        abort_unless((int) ($oldEntry['responsible_user_id'] ?? 0) === $actor && (int) ($entry['responsible_user_id'] ?? 0) === $actor, 403, 'Možete uređivati samo vlastitu uslugu.');
                    }
                }
            }
            abort_unless(array_key_exists('checklist_revision', $data) && $data['checklist_revision'] === $before['checklist_revision'],409,'Operativni zadaci su izmijenjeni. Osvježite tok.');
            foreach ($before['legs'] as $previousLeg) {
                if (in_array($previousLeg['key'],array_column($data['legs'],'key'),true)) continue;
                abort_if(($previousLeg['status'] ?? 'planned') !== 'planned' || collect($ops->read($order,false)['items'])->contains(fn($item)=>($item['leg_key'] ?? null) === $previousLeg['key'] && in_array($item['status'] ?? '',['in_progress','completed'],true)),422,'Započeta usluga se čuva u historiji i ne može se ukloniti.');
            }
            abort_if(array_diff(array_column($before['transit'],'key'),array_column($data['transit'],'key')),422,'Tranzitni predmeti se čuvaju u historiji i ne mogu se ukloniti.');
            abort_unless($before['revision'] === $data['revision'], 409, 'Tok je izmijenjen. Osvježite nalog.');
            abort_if($data['transport_type'] === 'multimodal' && count($data['legs']) < 2, 422, 'Multimodalni nalog traži najmanje dvije dionice.');
            foreach ($data['legs'] as &$leg) {
                $ops->member($order->company_id, $leg['responsible_user_id'] ?? null);
                if ($leg['status'] === 'delivered') {
                    abort_if(empty($leg['document_ids']), 422, 'Isporučena dionica traži dokaz primopredaje.');
                    $this->documents($order, $leg['document_ids']);
                    $leg['document_hashes']=$ops->documentHashes($leg['document_ids']);
                }
            }
            unset($leg);
            foreach ($data['transit'] as $transit) {
                $ops->member($order->company_id, $transit['responsible_user_id'] ?? null);
                if ($transit['status'] !== 'draft') {
                    abort_if(empty($transit['document_ids']), 422, 'Službeni tranzitni događaj traži dokument.');
                    $this->documents($order, $transit['document_ids']);
                    abort_if(in_array($transit['status'], ['released','discharged']) && empty($transit['mrn']), 422, 'Unesite MRN.');
                }
            }
            if ($order->status === 'closed') {
                $strip = fn ($legs) => array_map(function ($l) { unset($l['document_hashes']); return $l; },$legs);
                abort_unless($before['transport_type'] === $data['transport_type'] && $before['variant'] === ($data['variant'] ?? null) && $strip($before['legs']) === $strip($data['legs']),422,'Na završenom nalogu moguće je ažurirati samo zasebne tranzitne obaveze.');
                DB::table('ops_transport_flows')->where('order_id',$order->id)->update(['transit'=>json_encode($data['transit']),'revision'=>$before['revision']+1,'updated_at'=>now()]);
                $ops->audit($order->company_id,'transport_flow',$order->id,$actor,'transit_updated_after_completion',$before,$data);
                return $this->read($order);
            }
            $state = $ops->read($order);
            abort_if($data['checklist_revision'] !== $state['revision'],409,'Operativni zadaci su izmijenjeni. Osvježite tok.');
            $old = collect($state['items'])->keyBy('key');
            $items = $this->tasks($data['transport_type'], $data['legs'], $data['variant'] ?? null, app(OpsTaskAccess::class)->verifiedSeaOrganizer($order));
            $template = DB::table('ops_checklist_versions')->where('company_id',$order->company_id)->where('transport_type',$data['transport_type'])
                ->where('variant',$data['variant'] ?? null)->latest('version')->first();
            // Pin active jobs to their selected template; publishing a newer version affects new configurations only.
            $pinned = DB::table('ops_operational_state')->where('order_id',$order->id)->value('template_id');
            if ($before['active'] && $before['transport_type'] === $data['transport_type'] && $before['variant'] === ($data['variant'] ?? null)) $template = $pinned ? DB::table('ops_checklist_versions')->find($pinned) : null;
            foreach (json_decode($template?->items ?? '[]',true) as $custom) {
                if (in_array($custom['key'],['delivery','financial_review'])) continue;
                $position = array_search($custom['key'],array_column($items,'key'));
                $custom['generated']=true;
                if ($position === false) $items[]=$custom; else $items[$position]=$custom;
            }
            $structural = $before['transport_type'] !== $data['transport_type'] || $before['variant'] !== ($data['variant'] ?? null);
            $oldLegs = collect($before['legs'])->keyBy('key');
            $changedLegs = [];
            foreach ($data['legs'] as $leg) {
                $previous = $oldLegs->get($leg['key']);
                if ($previous && array_map(fn($k) => $previous[$k] ?? null, ['mode','variant','from','to']) !== array_map(fn($k) => $leg[$k] ?? null, ['mode','variant','from','to'])) $changedLegs[] = $leg['key'];
            }
            foreach ($items as &$item) {
                if ($old->has($item['key'])) {
                    $item = array_merge($old[$item['key']], $item, array_intersect_key($old[$item['key']], array_flip(['status','responsible_user_id','due_date','next_action','action_value','document_ids','completed_at','completed_by_user_id'])));
                    if (($structural || in_array($item['leg_key'] ?? null, $changedLegs, true)) && $item['status'] === 'completed') { $item['status']='pending'; $item['needs_review']=true; $item['completed_at']=null; $item['completed_by_user_id']=null; }
                }
                if (!empty($item['leg_key'])) {
                    $leg=collect($data['legs'])->firstWhere('key',$item['leg_key']);
                    if ($leg) { $item['responsible_user_id']=$leg['responsible_user_id'] ?? null; $item['due_date']=$leg['due_date'] ?? null; }
                }
            }
            unset($item);
            // Retain additional source shipment tasks; there is still one list, not a parallel OPS copy.
            if(isset($state['context']['intake']))$items=array_values(array_filter($items,fn($item)=>$old->has($item['key'])));
            $keys = array_column($items, 'key');
            foreach ($state['items'] as $item) if (!in_array($item['key'], $keys) && (empty($item['generated']) || !empty($item['verified_sea_only']) || !empty($item['optional_selected']) || !empty($item['controlled']))) $items[] = $item;
            if ($order->workspace_id) {
                $workspace = \App\Models\ShipmentWorkspace::whereKey($order->workspace_id)->lockForUpdate()->firstOrFail();
                $workspace->update(['operational_checklist'=>$items]);
            }
            $ops->initialize($order);
            DB::table('ops_operational_state')->where('order_id', $order->id)->update(['items'=>$order->workspace_id ? null : json_encode($items), 'template_id'=>$template?->id, 'revision'=>$state['revision']+1, 'updated_at'=>now()]);
            DB::table('ops_transport_flows')->updateOrInsert(['order_id'=>$order->id], ['company_id'=>$order->company_id, 'transport_type'=>$data['transport_type'],
                'variant'=>$data['variant'] ?? null, 'legs'=>json_encode($data['legs']), 'transit'=>json_encode($data['transit']), 'revision'=>$before['revision']+1,
                'created_at'=>now(), 'updated_at'=>now()]);
            DB::table('ops_orders')->where('id', $order->id)->increment('revision', 1, ['updated_at'=>now()]);
            $ops->audit($order->company_id, 'transport_flow', $order->id, $actor, 'updated', ['flow'=>$before,'checklist'=>$state], $data);
            return $this->read($order);
        });
    }

    public function documents(object $order, array $ids): void
    {
        $allowed = DB::table('ops_order_documents')->where('order_id', $order->id)->whereIn('document_id', $ids)->pluck('document_id')->all();
        abort_if(array_diff($ids, $allowed), 422, 'Dokaz mora pripadati ovom nalogu.');
        abort_unless(count(app(OperationalCompliance::class)->documentHashes($ids)) === count($ids), 422, 'Dokaz nije dostupan.');
    }
}
