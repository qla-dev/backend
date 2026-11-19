<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\VehicleRegistry;
use Illuminate\Console\Command;

final class AuditVehicleRegistry extends Command
{
    protected $signature='fleet:audit-registry {--summary : Report counts only}';
    protected $description='Read-only classification/capacity audit; never rewrites vehicle records';
    public function handle(): int
    {
        $counts=[];$total=0;
        foreach(Vehicle::select(['id','vehicle_type','transport_type','features','capacity_kg','capacity_m3'])->cursor() as $v){
            $total++;foreach(VehicleRegistry::warnings($v) as $warning)$counts[$warning]=($counts[$warning]??0)+1;
        }
        $this->line(json_encode(['vehicles_scanned'=>$total,'warnings'=>$counts,'changes'=>0,'note'=>'Warnings require review, not an automatic correction. No identifiers or registration numbers included.'],JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }
}
