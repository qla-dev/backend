<?php

use App\Http\Controllers\Api\AuthController;
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
use App\Http\Controllers\Api\LoadController;
use App\Http\Controllers\Api\LoadNoteController;
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

Route::get('health', fn () => response()->json(['message' => 'Smartfreight API is healthy.', 'data' => ['status' => 'ok', 'timestamp' => now()->toIso8601String()], 'meta' => [], 'errors' => []]));

Route::prefix('auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('shipments/{shipment}/invoice/{document}', ShipmentInvoiceDocumentController::class)
        ->where('document', 'predracun|a4-faktura');

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
