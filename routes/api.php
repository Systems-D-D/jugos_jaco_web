<?php

use App\Http\Controllers\AccountReceivableController;
use App\Http\Controllers\Api\AssignedProductController;
use App\Http\Controllers\Api\AssignedProductMovementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ResourceMediaController;
use App\Http\Controllers\ClientVisitDayController;
use App\Http\Controllers\ClientVisitController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\TypePriceController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);

    Route::prefix('clients')->group(function () {
        Route::get('/', [ClientController::class, 'getClients']);
        Route::post('/', [ClientController::class, 'createClient']);
        Route::put('/{id}', [ClientController::class, 'updateClient']);
        Route::post('/{id}/image/business', [ClientController::class, 'uploadBusinessImage']);
        Route::get('/{id}/images/business', [ClientController::class, 'getBusinessImages']);
        Route::get('/{id}/image/profile', [ClientController::class, 'getProfileImage']);
        Route::post('/{id}/image/profile', [ClientController::class, 'uploadProfileImage']);
        Route::post('/{client_id}/visit', [ClientVisitController::class, 'createVisit']);
        Route::delete('/{client_id}/visit', [ClientVisitController::class, 'deleteVisit']);
        
        Route::patch('/{client_id}/visit-days/reorder', [ClientVisitDayController::class, 'reorderVisitDays']);
        Route::get('/{client_id}/visit-days', [ClientVisitDayController::class, 'getVisitDays']);
        Route::post('/{client_id}/visit-days', [ClientVisitDayController::class, 'createVisitDay']);
        Route::get('/{client_id}/visit-days/{id}', [ClientVisitDayController::class, 'getVisitDayById']);
        Route::delete('/{client_id}/visit-days/{id}', [ClientVisitDayController::class, 'deleteVisitDay']);
    });

    Route::prefix('media')->group(function () {
        Route::delete('/{id}', [ResourceMediaController::class, 'deleteMedia']);
    });

    Route::prefix('employees')->group(function () {
        Route::get('/{id}', [EmployeeController::class, 'getEmployee']);
        Route::post('/{id}/location', [EmployeeController::class, 'createLocation']);
    });
    
    Route::prefix('products')->group(function () {
        Route::get('/assigned', [AssignedProductController::class, 'getProductAssigned']);
        Route::get('/', [ProductController::class, 'getProducts']);
    });

    Route::prefix('product-movements')->group(function () {
        Route::get('/', [AssignedProductMovementController::class, 'getMovements']);
        Route::post('/', [AssignedProductMovementController::class, 'createMovement']);
        Route::delete('/{id}', [AssignedProductMovementController::class, 'deleteMovement']);
    });

    Route::prefix('sales')->group(function () {
        Route::post('/', [SaleController::class, 'createSale']);
        Route::get('/', [SaleController::class, 'getSales']);
        Route::get('/{id}', [SaleController::class, 'getSaleDetailsBySaleId']);
    });

    Route::prefix('account-receivable')->group(function () {
        Route::get('/', [AccountReceivableController::class, 'getAccountReceivable']);
        Route::get('/payments', [AccountReceivableController::class, 'getPaymentsToDay']);
        Route::get('/{id}', [AccountReceivableController::class, 'getAccountReceivableById']);
        Route::post('/{id}/payments', [AccountReceivableController::class, 'processPayment']);
    });

    Route::prefix('type-prices')->group(function () {
        Route::get('/', [TypePriceController::class, 'GetTypePrices']);
    });
});
