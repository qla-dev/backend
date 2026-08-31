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
                $inspection = VehicleReturnInspection::query()->create([
                    'load_id' => $load->id,
                    'vehicle_id' => $vehicle->id,
                    'recorded_by_user_id' => $user->id,
                    'mileage_km' => $data['mileage_km'],
                    'fuel_level_percent' => $data['fuel_level_percent'],
                    'has_damage' => $data['has_damage'],
                    'damage_notes' => trim((string) ($data['damage_notes'] ?? '')) ?: null,
                    'parking_location' => trim((string) ($data['parking_location'] ?? '')) ?: null,
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
        $this->authorizeVehicle($request, $photo->inspection->vehicle);
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->response($photo->path, $photo->name, [
            'Content-Type' => $photo->mime_type,
            'Cache-Control' => 'private, max-age=3600',
        ], 'inline');
    }

    private function authorizeLoad(Request $request, Load $load, Vehicle $vehicle): void
    {
        $user = $request->user();
        $role = $user?->role?->name;
        $allowed = $user?->isSuperAdminOrMaster()
            || ($role === 'driver' && (int) $load->assigned_driver_user_id === (int) $user->id)
            || (in_array($role, ['company', 'manager', 'dispatcher', 'customs_officer'], true) && $user->companies()->whereKey($load->company_id)->exists());

        abort_unless($allowed && (int) $vehicle->id === (int) $load->vehicle_id, 403);
    }

    private function authorizeVehicle(Request $request, Vehicle $vehicle): void
    {
        $user = $request->user();
        $role = $user?->role?->name;
        $allowed = $user?->isSuperAdminOrMaster()
            || (in_array($role, ['company', 'manager', 'dispatcher', 'customs_officer'], true) && $user->companies()->whereKey($vehicle->company_id)->exists())
            || ($role === 'driver' && (
                (int) $vehicle->owner_user_id === (int) $user->id
                || (int) $vehicle->assigned_driver_user_id === (int) $user->id
                || $vehicle->permittedUsers()->whereKey($user->id)->exists()
                || $vehicle->loads()->where('assigned_driver_user_id', $user->id)->exists()
            ));

        abort_unless($allowed, 403);
    }
}
