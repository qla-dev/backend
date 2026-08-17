<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BulkLoadScanController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CompanyInvitationController;
use App\Http\Controllers\Api\CompanyMembershipController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\EmailCampaignController;
use App\Http\Controllers\Api\EmailCampaignRecipientController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\FleetAccessController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceItemController;
use App\Http\Controllers\Api\LoadInvoiceDocumentController;
use App\Http\Controllers\Api\LoadController;
use App\Http\Controllers\Api\LoadNoteController;
use App\Http\Controllers\Api\LoadScanController;
use App\Http\Controllers\Api\LoadStopController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\OfferController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RouteController;
use App\Http\Controllers\Api\RouteStopController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\ShipmentInvoiceDocumentController;
use App\Http\Controllers\Api\TrackingEventController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\VehicleLocationController;
use Illuminate\Support\Facades\Route;

Route::get('health', fn () => response()->json(['message' => 'Freightbook.ai API is healthy.', 'data' => ['status' => 'ok', 'timestamp' => now()->toIso8601String()], 'meta' => [], 'errors' => []]));

// Public: the social-registration screen needs to list roles before the user has a session token.
Route::get('role-options', [RoleController::class, 'options']);

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
    Route::get('loads/{load}/invoice/{document}', LoadInvoiceDocumentController::class)
        ->where('document', 'predracun|a4-faktura');
    Route::get('shipments/{shipment}/invoice/{document}', ShipmentInvoiceDocumentController::class)
        ->where('document', 'predracun|a4-faktura');
    Route::post('loads/bulk', [LoadController::class, 'bulkStore']);
    Route::post('load-scans/bulk', [BulkLoadScanController::class, 'store'])->middleware('throttle:5,1');
    Route::post('load-scans/bulk/text', [BulkLoadScanController::class, 'scanText'])->middleware('throttle:5,1');

    Route::apiResources([
        'vehicles' => VehicleController::class,
        'vehicle-locations' => VehicleLocationController::class,
        'loads' => LoadController::class,
        'load-stops' => LoadStopController::class,
        'offers' => OfferController::class,
        'shipments' => ShipmentController::class,
        'routes' => RouteController::class,
        'route-stops' => RouteStopController::class,
        'tracking-events' => TrackingEventController::class,
        'load-notes' => LoadNoteController::class,
        'documents' => DocumentController::class,
        'conversations' => ConversationController::class,
        'messages' => MessageController::class,
    ]);
    Route::apiResource('drivers', DriverController::class)->only(['index', 'show']);
    Route::get('customer-options', [CustomerController::class, 'options']);
    Route::post('load-scans', [LoadScanController::class, 'store'])->middleware('throttle:10,1');
    Route::post('load-scans/text', [LoadScanController::class, 'scanText'])->middleware('throttle:10,1');

    Route::middleware('role:company,superadmin')->group(function (): void {
        Route::apiResources([
            'company-memberships' => CompanyMembershipController::class,
            'company-invitations' => CompanyInvitationController::class,
            'fleet-access' => FleetAccessController::class,
        ]);
    });

    Route::middleware('role:finance,company,superadmin')->group(function (): void {
        Route::apiResources([
            'invoices' => InvoiceController::class,
            'invoice-items' => InvoiceItemController::class,
        ]);
    });

    Route::middleware('role:superadmin')->group(function (): void {
        Route::patch('loads/{load}/status', [LoadController::class, 'updateStatus']);
        Route::post('offers/{offer}/approve', [OfferController::class, 'approve']);
        Route::post('companies/onboard', [CompanyController::class, 'onboard']);
        Route::post('customers/{customer}/authorize', [CustomerController::class, 'authorizeCustomer']);
        Route::post('users/customer', [CustomerController::class, 'store']);
        Route::post('users/driver', [DriverController::class, 'store']);
        Route::post('drivers', [DriverController::class, 'store'])->name('drivers.store');
        Route::apiResources([
            'roles' => RoleController::class,
            'users' => UserController::class,
            'customers' => CustomerController::class,
            'companies' => CompanyController::class,
            'email-templates' => EmailTemplateController::class,
            'email-campaigns' => EmailCampaignController::class,
            'email-campaign-recipients' => EmailCampaignRecipientController::class,
        ]);
    });
});
