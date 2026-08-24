<?php

namespace App\Services;

// Kept as a compatibility alias for older callers. FB-C-26000 is a shipment tracking number,
// never an invoice/order booking reference; new code must depend on TrackingNumberGenerator.
class BookingReferenceGenerator extends TrackingNumberGenerator {}
