<?php

namespace App\Observers;

use App\Models\ShipmentWorkspace;
use App\Services\Ops\OpsOrders;

/**
 * A load that becomes a booked shipment becomes a work order (špediterski nalog) in the same
 * transaction; later shipment status changes move the work order along. See OpsOrders.
 */
class ShipmentWorkspaceOpsObserver
{
    public function __construct(private OpsOrders $orders) {}

    public function created(ShipmentWorkspace $workspace): void
    {
        $this->orders->createFromWorkspace($workspace);
    }

    public function updated(ShipmentWorkspace $workspace): void
    {
        if ($workspace->wasChanged('status')) {
            $this->orders->followWorkspace($workspace);
        }
    }
}
