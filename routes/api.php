<?php

use App\Http\Controllers\Api\AiCallLogController;
use App\Http\Controllers\Api\AircraftController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BulkLoadScanController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CompanyInvitationController;
use App\Http\Controllers\Api\CompanyMembershipController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CustomsDocumentController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DispatchChatController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\EmailCampaignController;
use App\Http\Controllers\Api\EmailCampaignRecipientController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\FleetAccessController;
use App\Http\Controllers\Api\FuelStationController;
use App\Http\Controllers\Api\HsCodeController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceItemController;
use App\Http\Controllers\Api\LenaGuidedAnswerController;
use App\Http\Controllers\Api\LandingStatsController;
use App\Http\Controllers\Api\LoadController;
use App\Http\Controllers\Api\LoadDraftController;
use App\Http\Controllers\Api\LoadInvoiceDocumentController;
use App\Http\Controllers\Api\LoadNoteController;
use App\Http\Controllers\Api\LoadScanController;
use App\Http\Controllers\Api\LoadStopController;
use App\Http\Controllers\Api\MessageAttachmentController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\OfferController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentInvoiceDocumentController;
use App\Http\Controllers\Api\PublicTrackingController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RouteController;
use App\Http\Controllers\Api\RouteStopController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\ShipmentInvoiceDocumentController;
use App\Http\Controllers\Api\SubscriptionPackageController;
use App\Http\Controllers\Api\TrackingEventController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserSubscriptionController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\VehicleLocationController;
use App\Http\Controllers\Api\VehicleReturnInspectionController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\WarehouseMovementController;
use Illuminate\Support\Facades\Route;

Route::get('health', fn () => response()->json(['message' => 'Freightbook.ai API is healthy.', 'data' => ['status' => 'ok', 'timestamp' => now()->toIso8601String()], 'meta' => [], 'errors' => []]));

// Public: the social-registration screen needs to list roles before the user has a session token.
Route::get('role-options', [RoleController::class, 'options']);

// Public landing-page preview. This returns only safe summary fields for currently posted loads.
Route::get('public-loads', [LoadController::class, 'publicIndex']);

// Public landing-page pricing table - same active packages the in-app Pricing screen shows.
Route::get('public-subscription-packages', [SubscriptionPackageController::class, 'index']);

// Public landing-page module counts. Aggregate values only; no customer or tariff data is exposed.
Route::get('public-module-counts', [LandingStatsController::class, 'index'])->middleware('throttle:60,1');

// Exact-number public shipment lookup used by the landing-page Lena tracking flow.
Route::get('public-tracking/{trackingNumber}', [PublicTrackingController::class, 'show'])
    ->where('trackingNumber', 'FB-[CRLZS]-[0-9]{5}')
    ->middleware('throttle:30,1');

Route::prefix('auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('google', [AuthController::class, 'google']);
    Route::post('apple', [AuthController::class, 'apple']);
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('fuel-stations', [FuelStationController::class, 'index'])->middleware('throttle:120,1');
    Route::get('vehicles/{vehicle}/return-inspections', [VehicleReturnInspectionController::class, 'index']);
    Route::get('vehicle-return-photos/{photo}', [VehicleReturnInspectionController::class, 'photo']);
    Route::get('reviews', [ReviewController::class, 'index']);
    Route::post('reviews', [ReviewController::class, 'store'])->middleware('throttle:20,1');
    Route::get('loads/{load}/invoice/{document}', LoadInvoiceDocumentController::class)
        ->where('document', 'predracun|a4-faktura');
    Route::get('shipments/{shipment}/invoice/{document}', ShipmentInvoiceDocumentController::class)
        ->where('document', 'predracun|a4-faktura');
    Route::post('load-scans/bulk', [BulkLoadScanController::class, 'store'])->middleware('throttle:5,1');
    Route::post('load-scans/bulk/text', [BulkLoadScanController::class, 'scanText'])->middleware('throttle:5,1');
    Route::get('loads/tracking-status-counts', [LoadController::class, 'trackingStatusCounts']);
    Route::get('loads/profile-status-counts', [LoadController::class, 'profileStatusCounts']);

    // Customers, independent drivers, and logistics companies can post loads; superadmin/master keep their usual override.
    Route::middleware('role:user,driver,company,manager,dispatcher,customs_officer,superadmin,master')->group(function (): void {
        Route::post('loads', [LoadController::class, 'store']);
        Route::post('loads/bulk', [LoadController::class, 'bulkStore']);
    });

    // Warehouses are user-owned facilities. These roles may create and manage their own rows;
    // protected admins may additionally manage any facility in the network.
    Route::middleware('role:user,driver,company,manager,dispatcher,customs_officer,warehouse,superadmin,master')->group(function (): void {
        Route::get('warehouse/overview', [WarehouseController::class, 'overview']);
        Route::get('warehouses/{warehouse}/status', [WarehouseController::class, 'status']);
        Route::post('warehouses', [WarehouseController::class, 'store']);
        Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update']);
        Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy']);
    });

    // Instant-booking a posted, non-negotiable load. Drivers book for themselves; companies book
    // for their company (optionally assigning a driver right away); superadmin/master can assign either.
    Route::middleware('role:driver,company,manager,dispatcher,customs_officer,superadmin,master')->group(function (): void {
        Route::post('loads/{load}/book', [LoadController::class, 'book']);
    });

    Route::post('documents/upload', [DocumentController::class, 'upload']);
    Route::get('documents/{document}/download', [DocumentController::class, 'download']);

    Route::apiResources([
        'vehicles' => VehicleController::class,
        'vehicle-locations' => VehicleLocationController::class,
        'load-stops' => LoadStopController::class,
        'offers' => OfferController::class,
        'shipments' => ShipmentController::class,
        'routes' => RouteController::class,
        'route-stops' => RouteStopController::class,
        'tracking-events' => TrackingEventController::class,
        'load-notes' => LoadNoteController::class,
        'load-drafts' => LoadDraftController::class,
        'documents' => DocumentController::class,
        'conversations' => ConversationController::class,
        'messages' => MessageController::class,
    ]);
    Route::apiResource('loads', LoadController::class)->except(['store']);
    Route::apiResource('warehouses', WarehouseController::class)->only(['index', 'show']);
    // The dock ledger a warehouse account works its day from - see WarehouseMovementController.
    Route::apiResource('warehouse-movements', WarehouseMovementController::class);
    Route::apiResource('drivers', DriverController::class)->only(['index', 'show']);
    Route::apiResource('customers', CustomerController::class)->only(['index', 'show']);
    Route::get('customer-options', [CustomerController::class, 'options']);
    Route::post('dispatch-chat', [DispatchChatController::class, 'store'])->middleware('throttle:20,1');
    Route::post('lena-guided-answer', [LenaGuidedAnswerController::class, 'store'])->middleware('throttle:30,1');
    Route::post('load-scans', [LoadScanController::class, 'store'])->middleware('throttle:10,1');
    Route::post('load-scans/text', [LoadScanController::class, 'scanText'])->middleware('throttle:10,1');
    Route::post('message-attachments', [MessageAttachmentController::class, 'store'])->middleware('throttle:20,1');
    Route::get('message-attachments/{conversation}/{filename}', [MessageAttachmentController::class, 'show'])
        ->where('filename', '[a-f0-9\-]+\.[a-zA-Z0-9]+');
    Route::get('tariffs/categories', [HsCodeController::class, 'categories'])->middleware('throttle:60,1');
    Route::get('tariffs/catalog', [HsCodeController::class, 'catalog'])->middleware('throttle:60,1');
    Route::get('hs-codes', [HsCodeController::class, 'index'])->middleware('throttle:60,1');
    Route::post('hs-codes/bulk', [HsCodeController::class, 'bulk'])->middleware('throttle:60,1');
    Route::get('customs-documents', [CustomsDocumentController::class, 'index'])->middleware('throttle:60,1');
    Route::post('customs-documents/match', [CustomsDocumentController::class, 'match'])->middleware('throttle:60,1');
    Route::post('loads/{load}/customs-documents/{code}/download', [CustomsDocumentController::class, 'download'])
        ->where('code', '[A-Za-z0-9 ]+');

    // Pricing is visible to every role - only managing the catalog/assignments is admin-only below.
    Route::get('subscription-packages', [SubscriptionPackageController::class, 'index']);
    Route::get('subscription-packages/{id}', [SubscriptionPackageController::class, 'show']);
    Route::get('my-subscription', [UserSubscriptionController::class, 'me']);
    Route::post('my-subscription', [UserSubscriptionController::class, 'selectMine']);
    Route::get('my-usage', [UsageController::class, 'mine']);
    Route::get('payments', [PaymentController::class, 'index']);
    Route::get('payments/{payment}/invoice', PaymentInvoiceDocumentController::class);
    Route::post('payments', [PaymentController::class, 'store'])->middleware('throttle:20,1');

    Route::middleware('role:company,manager,superadmin,master')->group(function (): void {
        Route::get('team-role-options', [RoleController::class, 'teamOptions']);
        Route::get('company-invitations/available-users', [CompanyInvitationController::class, 'availableUsers']);
        Route::apiResources([
            'company-memberships' => CompanyMembershipController::class,
            'company-invitations' => CompanyInvitationController::class,
            'fleet-access' => FleetAccessController::class,
        ]);
    });

    Route::middleware('role:finance,company,manager,superadmin,master')->group(function (): void {
        Route::apiResources([
            'invoices' => InvoiceController::class,
            'invoice-items' => InvoiceItemController::class,
        ]);
    });

    Route::patch('loads/{load}/status', [LoadController::class, 'updateStatus'])
        ->middleware('role:user,driver,company,manager,dispatcher,customs_officer,superadmin,master');

    Route::post('loads/{load}/vehicle-return', [VehicleReturnInspectionController::class, 'store'])
        ->middleware('role:driver,company,manager,dispatcher,customs_officer,superadmin,master');

    Route::middleware('role:superadmin,master')->group(function (): void {
        Route::get('aircraft', [AircraftController::class, 'index'])->middleware('throttle:30,1');
        Route::post('offers/{offer}/approve', [OfferController::class, 'approve']);
        Route::post('companies/onboard', [CompanyController::class, 'onboard']);
        Route::post('warehouses/onboard', [WarehouseController::class, 'onboard']);
        Route::post('customers/{customer}/authorize', [CustomerController::class, 'authorizeCustomer']);
        Route::post('users/customer', [CustomerController::class, 'store']);
        Route::post('users/driver', [DriverController::class, 'store']);
        Route::post('drivers', [DriverController::class, 'store'])->name('drivers.store');
        Route::post('subscription-packages', [SubscriptionPackageController::class, 'store']);
        Route::put('subscription-packages/{id}', [SubscriptionPackageController::class, 'update']);
        Route::delete('subscription-packages/{id}', [SubscriptionPackageController::class, 'destroy']);
        Route::get('user-subscriptions', [UserSubscriptionController::class, 'index']);
        Route::post('user-subscriptions/{user}', [UserSubscriptionController::class, 'store']);
        Route::delete('user-subscriptions/{userSubscription}', [UserSubscriptionController::class, 'destroy']);
        Route::apiResources([
            'roles' => RoleController::class,
            'users' => UserController::class,
            'companies' => CompanyController::class,
            'email-templates' => EmailTemplateController::class,
            'email-campaigns' => EmailCampaignController::class,
            'email-campaign-recipients' => EmailCampaignRecipientController::class,
        ]);
        Route::apiResource('customers', CustomerController::class)->except(['index', 'show']);
    });

    // AI Stats is viewable by superadmin too, but permanently purging a conversation stays
    // master-exclusive.
    Route::middleware('role:master,superadmin')->group(function (): void {
        Route::apiResource('ai-call-logs', AiCallLogController::class)->only(['index', 'show']);
    });
    Route::middleware('role:master')->group(function (): void {
        Route::delete('ai-call-logs/conversation/{conversation}', [AiCallLogController::class, 'purgeConversation']);
    });
});
