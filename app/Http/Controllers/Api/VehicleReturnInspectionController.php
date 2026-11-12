<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Load;
use App\Models\Vehicle;
use App\Models\VehicleReturnInspection;
use App\Models\VehicleReturnPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class VehicleReturnInspectionController extends Controller
{
    public function index(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorizeVehicle($request, $vehicle);

        $inspections = $vehicle->returnInspections()
            ->with(['photos:id,vehicle_return_inspection_id,name,mime_type,size_bytes', 'recordedBy:id,name'])
            ->limit(20)
            ->get();

        return response()->json([
            'message' => 'Vehicle return history retrieved.',
            'data' => $inspections,
            'meta' => ['count' => $inspections->count()],
            'errors' => [],
        ]);
    }

    public function store(Request $request, Load $load): JsonResponse
    {
        $user = $request->user();
        abort_unless($load->vehicle_id, 422, 'Assign a vehicle before completing the car drop.');
        abort_if($load->vehicleReturnInspection()->exists(), 409, 'A vehicle return is already recorded for this load.');
        $vehicle = Vehicle::query()->findOrFail($load->vehicle_id);
        $this->authorizeLoad($request, $load, $vehicle);

        $data = $request->validate([
            'mileage_km' => ['required', 'integer', 'min:0', 'max:999999999'],
            'fuel_level_percent' => ['required', 'integer', 'between:0,100'],
            'has_damage' => ['required', 'boolean'],
            'damage_notes' => ['nullable', 'required_if:has_damage,1,true', 'string', 'max:5000'],
            'parking_location' => ['nullable', 'string', 'max:255'],
            'parking_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:parking_longitude'],
            'parking_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:parking_latitude'],
            'photos' => ['required', 'array', 'min:3', 'max:10'],
            'photos.*' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif', 'max:12288'],
        ], [
            'photos.min' => 'Add at least three current parking-lot photos.',
            'damage_notes.required_if' => 'Describe the reported damage.',
        ]);

        $lastMileage = VehicleReturnInspection::query()
            ->where('vehicle_id', $vehicle->id)
            ->max('mileage_km');
        if ($lastMileage !== null && (int) $data['mileage_km'] < (int) $lastMileage) {
            return response()->json([
                'message' => 'Mileage cannot be lower than the vehicle’s previous return record.',
                'data' => null,
                'errors' => ['mileage_km' => ["Previous recorded mileage: {$lastMileage} km."]],
            ], 422);
        }

        $storedPaths = [];
        try {
            $inspection = DB::transaction(function () use ($request, $user, $load, $vehicle, $data, &$storedPaths): VehicleReturnInspection {
                if ($vehicle->company_id) DB::table('companies')->where('id', $vehicle->company_id)->lockForUpdate()->firstOrFail();
                $vehicle->refresh();
                Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
                abort_if($vehicle->assigned_driver_user_id && $load->assigned_driver_user_id && (int)$vehicle->assigned_driver_user_id !== (int)$load->assigned_driver_user_id,422,'The route driver does not match the vehicle assignment. Record the correct handover first.');
                abort_if($load->vehicleReturnInspection()->exists(), 409, 'A vehicle return is already recorded for this load.');
                $inspection = VehicleReturnInspection::query()->create([
                    'load_id' => $load->id,
                    'vehicle_id' => $vehicle->id,
                    'recorded_by_user_id' => $user->id,
                    'mileage_km' => $data['mileage_km'],
                    'fuel_level_percent' => $data['fuel_level_percent'],
                    'has_damage' => $data['has_damage'],
                    'damage_notes' => trim((string) ($data['damage_notes'] ?? '')) ?: null,
                    'parking_location' => trim((string) ($data['parking_location'] ?? '')) ?: null,
                    'parking_latitude' => $data['parking_latitude'] ?? null,
                    'parking_longitude' => $data['parking_longitude'] ?? null,
                    'inspected_at' => now(),
                ]);

                foreach ($request->file('photos', []) as $file) {
                    $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg'));
                    $path = $file->storeAs(
                        "vehicle-returns/{$vehicle->id}/{$inspection->id}",
                        Str::uuid()->toString().'.'.$extension,
                        'local',
                    );
                    $storedPaths[] = $path;
                    $inspection->photos()->create([
                        'name' => $file->getClientOriginalName(),
                        'path' => $path,
                        'mime_type' => $file->getClientMimeType() ?: 'image/jpeg',
                        'size_bytes' => $file->getSize(),
                    ]);
                }

                $vehicleRole=$vehicle->company_id?app(\App\Services\FleetOperations::class)->role($user,(int)$vehicle->company_id):null;
                $canTransfer=in_array($vehicleRole,['superadmin','master','company','manager','dispatcher'],true)
                    || ((int)$vehicle->assigned_driver_user_id===(int)$user->id&&(int)$load->assigned_driver_user_id===(int)$user->id);
                if ($vehicle->company_id && $canTransfer) {
                    app(\App\Services\FleetOperations::class)->handover($vehicle, $user->id, [
                        'load_id'=>$load->id, 'inspection_id'=>$inspection->id, 'destination'=>'depot',
                        'depot_name'=>isset($data['parking_location'])?mb_substr($data['parking_location'],0,120):$vehicle->depot_name,
                        'mileage_km'=>$data['mileage_km'], 'fuel_level_percent'=>$data['fuel_level_percent'],
                        'has_damage'=>$data['has_damage'], 'notes'=>$data['damage_notes'] ?? null,
                    ]);
                }
                if ($workspace = $load->shipmentWorkspace) {
                    $workspace->update(['operational_checklist' => array_map(fn ($item) =>
                        ($item['key'] ?? '') === 'vehicle_return'
                            ? array_merge($item, ['status' => 'completed', 'completed_at' => now()->toIso8601String(), 'completed_by_user_id' => $user->id])
                            : $item, $workspace->operational_checklist)]);
                }
                $load->update(['status' => 'finished', 'completed_at' => now()]);

                return $inspection->load(['photos', 'recordedBy:id,name']);
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return response()->json([
            'message' => 'Vehicle returned and load marked as finished.',
            'data' => $inspection,
            'meta' => [],
            'errors' => [],
        ], 201);
    }

    public function photo(Request $request, VehicleReturnPhoto $photo): StreamedResponse
    {
        $photo->loadMissing('inspection.vehicle');
        $own=(int)$photo->inspection->recorded_by_user_id===(int)$request->user()->id;
        $handover=DB::table('fleet_handovers')->where('inspection_id',$photo->inspection->id)->where(fn($q)=>$q->where('from_driver_id',$request->user()->id)->orWhere('to_driver_id',$request->user()->id))->exists();
        if(!$own&&!$handover)$this->authorizeVehicle($request, $photo->inspection->vehicle);
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->response($photo->path, $photo->name, [
            'Content-Type' => $photo->mime_type,
            'Cache-Control' => 'private, max-age=3600',
        ], 'inline');
    }

    private function authorizeLoad(Request $request, Load $load, Vehicle $vehicle): void
    {
        $user = $request->user();
        $role = $load->company_id ? app(\App\Services\FleetOperations::class)->role($user,(int)$load->company_id) : null;
        $isDriver=$user?->role?->name==='driver'||($vehicle->company_id&&app(\App\Services\FleetOperations::class)->role($user,(int)$vehicle->company_id)==='driver');
        $allowed = $user?->isSuperAdminOrMaster()
            || ($isDriver && (int) $load->assigned_driver_user_id === (int) $user->id)
            || in_array($role, ['company', 'manager', 'dispatcher'], true);

        abort_unless($allowed && (int) $vehicle->id === (int) $load->vehicle_id, 403);
    }

    private function authorizeVehicle(Request $request, Vehicle $vehicle): void
    {
        $user = $request->user();
        $role = $vehicle->company_id ? app(\App\Services\FleetOperations::class)->role($user,(int)$vehicle->company_id) : $user?->role?->name;
        $allowed = $user?->isSuperAdminOrMaster()
            || in_array($role, ['company', 'manager', 'dispatcher'], true)
            || ($role === 'driver' && (
                (int) $vehicle->owner_user_id === (int) $user->id
                || (int) $vehicle->assigned_driver_user_id === (int) $user->id
                || $vehicle->permittedUsers()->whereKey($user->id)->where('fleet_access.can_view',true)->exists()
            ));

        abort_unless($allowed, 403);
    }
}
