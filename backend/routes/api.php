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
    });
});
