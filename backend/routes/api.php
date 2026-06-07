<?php

use App\Http\Controllers\Admin\CompanySettingController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/activate', [AuthController::class, 'activate']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::prefix('admin')->group(function () {
        Route::get('/company-settings', [CompanySettingController::class, 'show']);
        Route::patch('/company-settings', [CompanySettingController::class, 'update']);
        
        Route::get('/users', [\App\Http\Controllers\Admin\UserController::class, 'index']);
        Route::get('/users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'show']);
        Route::post('/users/{user}/deactivate', [\App\Http\Controllers\Admin\UserController::class, 'deactivate']);
        Route::post('/users/{user}/reactivate', [\App\Http\Controllers\Admin\UserController::class, 'reactivate']);
        
        Route::get('/roles', [\App\Http\Controllers\Admin\RoleController::class, 'index']);
        
        Route::get('/employees', [\App\Http\Controllers\Admin\EmployeeController::class, 'index']);
        Route::post('/employees/import', [\App\Http\Controllers\Admin\EmployeeController::class, 'import']);
        Route::post('/employees/{employee}/provision-user', [\App\Http\Controllers\Admin\EmployeeController::class, 'provisionUser']);
        
        Route::get('/erp/sync-jobs', [\App\Http\Controllers\Admin\ErpSyncController::class, 'index']);
        Route::post('/erp/sync-assets', [\App\Http\Controllers\Admin\ErpSyncController::class, 'syncAssets']);
        Route::post('/erp/sync-parts', [\App\Http\Controllers\Admin\ErpSyncController::class, 'syncParts']);
        
        Route::get('/locations', [\App\Http\Controllers\Admin\MasterDataController::class, 'indexLocations']);
        Route::post('/locations', [\App\Http\Controllers\Admin\MasterDataController::class, 'storeLocation']);
        Route::patch('/locations/{location}', [\App\Http\Controllers\Admin\MasterDataController::class, 'updateLocation']);
        
        Route::get('/master-data/{groupKey}', [\App\Http\Controllers\Admin\MasterDataController::class, 'indexMasterData']);
        Route::post('/master-data/{groupKey}', [\App\Http\Controllers\Admin\MasterDataController::class, 'storeMasterDataItem']);
        Route::patch('/master-data/items/{item}', [\App\Http\Controllers\Admin\MasterDataController::class, 'updateMasterDataItem']);
        
        Route::get('/usage-reading-types', [\App\Http\Controllers\Admin\MasterDataController::class, 'indexUsageReadingTypes']);
        Route::post('/usage-reading-types', [\App\Http\Controllers\Admin\MasterDataController::class, 'storeUsageReadingType']);
        Route::patch('/usage-reading-types/{type}', [\App\Http\Controllers\Admin\MasterDataController::class, 'updateUsageReadingType']);
    });

    Route::get('/assets', [\App\Http\Controllers\AssetController::class, 'index']);
    Route::get('/assets/{asset}', [\App\Http\Controllers\AssetController::class, 'show']);
    Route::get('/assets/{asset}/meter-readings', [\App\Http\Controllers\AssetController::class, 'meterReadings']);
    Route::get('/assets/{asset}/location-history', [\App\Http\Controllers\AssetController::class, 'locationHistory']);
    
    Route::post('/assets/{asset}/location', [\App\Http\Controllers\AssetLocationController::class, 'update']);
    Route::post('/assets/{asset}/meter-readings', [\App\Http\Controllers\AssetMeterReadingController::class, 'store']);
    Route::post('/assets/{asset}/meter-readings/{reading}/confirm', [\App\Http\Controllers\AssetMeterReadingController::class, 'confirm']);
});
