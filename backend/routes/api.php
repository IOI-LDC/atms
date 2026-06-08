<?php

use App\Http\Controllers\Admin\CompanySettingController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\ErpSyncController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetLocationController;
use App\Http\Controllers\AssetMeterReadingController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MaintenanceRequestController;
use App\Http\Controllers\PmRuleController;
use App\Http\Controllers\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/activate', [AuthController::class, 'activate'])->middleware('throttle:5,1');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::prefix('admin')->group(function () {
        Route::get('/company-settings', [CompanySettingController::class, 'show']);
        Route::patch('/company-settings', [CompanySettingController::class, 'update']);

        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
        Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate']);

        Route::get('/roles', [RoleController::class, 'index']);

        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::post('/employees/import', [EmployeeController::class, 'import']);
        Route::post('/employees/{employee}/provision-user', [EmployeeController::class, 'provisionUser']);

        Route::get('/erp/sync-jobs', [ErpSyncController::class, 'index']);
        Route::post('/erp/sync-assets', [ErpSyncController::class, 'syncAssets']);
        Route::post('/erp/sync-parts', [ErpSyncController::class, 'syncParts']);

        Route::get('/locations', [MasterDataController::class, 'indexLocations']);
        Route::post('/locations', [MasterDataController::class, 'storeLocation']);
        Route::patch('/locations/{location}', [MasterDataController::class, 'updateLocation']);

        Route::get('/master-data/{groupKey}', [MasterDataController::class, 'indexMasterData']);
        Route::post('/master-data/{groupKey}', [MasterDataController::class, 'storeMasterDataItem']);
        Route::patch('/master-data/items/{item}', [MasterDataController::class, 'updateMasterDataItem']);

        Route::get('/usage-reading-types', [MasterDataController::class, 'indexUsageReadingTypes']);
        Route::post('/usage-reading-types', [MasterDataController::class, 'storeUsageReadingType']);
        Route::patch('/usage-reading-types/{type}', [MasterDataController::class, 'updateUsageReadingType']);
    });

    Route::get('/assets', [AssetController::class, 'index']);
    Route::get('/assets/{asset}', [AssetController::class, 'show']);
    Route::get('/assets/{asset}/meter-readings', [AssetController::class, 'meterReadings']);
    Route::get('/assets/{asset}/location-history', [AssetController::class, 'locationHistory']);

    Route::post('/assets/{asset}/location', [AssetLocationController::class, 'update']);
    Route::post('/assets/{asset}/meter-readings', [AssetMeterReadingController::class, 'store']);
    Route::post('/assets/{asset}/meter-readings/{reading}/confirm', [AssetMeterReadingController::class, 'confirm']);

    Route::get('/maintenance-requests', [MaintenanceRequestController::class, 'index']);
    Route::post('/maintenance-requests/corrective', [MaintenanceRequestController::class, 'storeCorrective']);
    Route::get('/maintenance-requests/{maintenanceRequest}', [MaintenanceRequestController::class, 'show']);
    Route::post('/maintenance-requests/{maintenanceRequest}/approve', [MaintenanceRequestController::class, 'approve']);
    Route::post('/maintenance-requests/{maintenanceRequest}/reject', [MaintenanceRequestController::class, 'reject']);
    Route::post('/maintenance-requests/{maintenanceRequest}/cancel', [MaintenanceRequestController::class, 'cancel']);

    Route::get('/work-orders', [WorkOrderController::class, 'index']);
    Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show']);
    Route::patch('/work-orders/{workOrder}', [WorkOrderController::class, 'update']);
    Route::post('/work-orders/{workOrder}/assign', [WorkOrderController::class, 'assign']);
    Route::post('/work-orders/{workOrder}/start', [WorkOrderController::class, 'start']);
    Route::post('/work-orders/{workOrder}/complete', [WorkOrderController::class, 'complete']);
    Route::post('/work-orders/{workOrder}/close', [WorkOrderController::class, 'close']);
    Route::post('/work-orders/{workOrder}/cancel', [WorkOrderController::class, 'cancel']);
    Route::post('/work-orders/{workOrder}/parts', [WorkOrderController::class, 'addPart']);
    Route::delete('/work-orders/{workOrder}/parts/{partLine}', [WorkOrderController::class, 'removePart']);

    Route::get('/pm-rules', [PmRuleController::class, 'index']);
    Route::post('/pm-rules', [PmRuleController::class, 'store']);
    Route::get('/pm-rules/{pmRule}', [PmRuleController::class, 'show']);
    Route::patch('/pm-rules/{pmRule}', [PmRuleController::class, 'update']);
    Route::post('/pm-rules/{pmRule}/deactivate', [PmRuleController::class, 'deactivate']);
    Route::post('/pm-rules/{pmRule}/reactivate', [PmRuleController::class, 'reactivate']);
    Route::post('/pm-rules/{pmRule}/evaluate', [PmRuleController::class, 'evaluate']);
    Route::post('/pm-rules/evaluate', [PmRuleController::class, 'evaluateAll']);
});
