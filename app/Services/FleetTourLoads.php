<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class FleetTourLoads
{
    private function available(int $company): Builder
    {
        return DB::table('loads')->where(function (Builder $query) use ($company): void {
            $query->where('loads.company_id', $company)->orWhereIn('loads.id',
                DB::table('shipment_workspace')->where('provider_company_id', $company)->select('load_id'));
        });
    }

    public function search(int $company, string $search): Collection
    {
        return $this->available($company)->where(function (Builder $query) use ($search): void {
            foreach (['tracking_number', 'booking_reference', 'title'] as $column) {
                $query->orWhere($column, 'like', '%'.$search.'%');
            }
        })->latest('id')->limit(30)->get(['id', 'tracking_number', 'booking_reference', 'title']);
    }

    public function replace(int $company, int $tour, array $ids): void
    {
        abort_unless($this->available($company)->whereIn('loads.id', $ids)->count() === count($ids), 422, 'Select loads from this company.');
        $links = DB::table('fleet_tour_loads')->where('tour_id', $tour);
        $links->whereNotIn('load_id', $ids)->delete();
        foreach (array_values($ids) as $index => $loadId) {
            DB::table('fleet_tour_loads')->updateOrInsert(['tour_id' => $tour, 'load_id' => $loadId], ['position' => $index]);
        }
    }

    public function forTours(int $company, array $tourIds): Collection
    {
        if ($tourIds === []) {
            return collect();
        }

        $loads = $this->available($company)->join('fleet_tour_loads as link', 'link.load_id', '=', 'loads.id')
            ->whereIn('link.tour_id', $tourIds)->orderBy('link.position')
            ->get(['link.tour_id', 'loads.id', 'loads.tracking_number', 'loads.booking_reference', 'loads.title']);
        $stops = DB::table('load_stops')->whereIn('load_id', $loads->pluck('id')->unique())
            ->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('position')
            ->get(['load_id', 'latitude', 'longitude'])->groupBy('load_id');

        foreach ($loads as $load) {
            $load->points = $stops->get($load->id, collect())->map(fn ($stop) => [(float) $stop->latitude, (float) $stop->longitude])->values();
        }

        return $loads->groupBy('tour_id');
    }

    public function canViewThroughTour(User $user, int $loadId): bool
    {
        if (! Schema::hasTable('fleet_tour_loads')) {
            return false;
        }

        $tours = DB::table('fleet_tours as t')->join('fleet_tour_loads as link', 'link.tour_id', '=', 't.id')
            ->where('link.load_id', $loadId);
        if ($user->role?->name === 'driver') {
            $tours->where('t.driver_id', $user->id);
        }

        foreach ((clone $tours)->distinct()->pluck('t.company_id') as $company) {
            try {
                $scope = app(FleetDepotAccess::class)->query((clone $tours)->where('t.company_id', $company), $user, $company, 'tour', 't');
                if ($scope->exists() && $this->available($company)->where('loads.id', $loadId)->exists()) {
                    return true;
                }
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 403) {
                    throw $exception;
                }
            }
        }

        return false;
    }
}
