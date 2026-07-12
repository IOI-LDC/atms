<?php

use App\Http\Controllers\Admin\ApiClientController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CompanySettingController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\ErpSyncController;
use App\Http\Controllers\Admin\FaSubclassTypeCodeController;
use App\Http\Controllers\Admin\FormTemplateController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AssetBookingController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetLocationController;
use App\Http\Controllers\AssetMeterReadingController;
use App\Http\Controllers\AssetPmAssignmentController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\TokenController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardKpiController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ListOptionController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MaintenanceRequestController;
use App\Http\Controllers\PartController;
use App\Http\Controllers\PmRuleController;
use App\Http\Controllers\WorkOrderController;
use App\Http\Middleware\EnsureTokenAbilities;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/auth/activate', [AuthController::class, 'activate'])->middleware('throttle:5,1');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

Route::post('/auth/token', [TokenController::class, 'issue'])->middleware('throttle:5,1');

Route::middleware(['auth:sanctum', EnsureTokenAbilities::class])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/kpis', [DashboardKpiController::class, 'index']);

    Route::prefix('reports')->group(function () {
        Route::get('/upcoming-pm', [ReportController::class, 'upcomingPm']);
        Route::get('/assets-by-location', [ReportController::class, 'assetsByLocation']);
        Route::get('/pm-compliance', [ReportController::class, 'pmCompliance']);
        Route::get('/overdue-pm', [ReportController::class, 'overduePm']);
        Route::get('/asset-status-distribution', [ReportController::class, 'assetStatusDistribution']);
        Route::get('/wo-backlog', [ReportController::class, 'woBacklog']);
    });

    Route::get('/locations', [LocationController::class, 'index']);

    // Public (auth-only) read path for dropdown vocabulary. Admin writes remain
    // under the admin-prefixed master-data CRUD above.
    Route::get('/list-options/{group}', [ListOptionController::class, 'index']);

    Route::prefix('admin')->group(function () {
        Route::get('/company-settings', [CompanySettingController::class, 'show']);
        Route::patch('/company-settings', [CompanySettingController::class, 'update']);

        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
        Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate']);

        Route::get('/roles', [RoleController::class, 'index']);

        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::post('/employees/import', [EmployeeController::class, 'import']);
        Route::post('/employees/provision-user', [EmployeeController::class, 'provisionUser']);

        Route::get('/erp/sync-jobs', [ErpSyncController::class, 'index']);
        Route::post('/erp/sync-parts', [ErpSyncController::class, 'syncParts']);

        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        Route::get('/locations', [MasterDataController::class, 'indexLocations']);
        Route::post('/locations', [MasterDataController::class, 'storeLocation']);
        Route::patch('/locations/{location}', [MasterDataController::class, 'updateLocation']);

        Route::get('/master-data/{groupKey}', [MasterDataController::class, 'indexMasterData']);
        Route::post('/master-data/{groupKey}', [MasterDataController::class, 'storeMasterDataItem']);
        Route::patch('/master-data/items/{item}', [MasterDataController::class, 'updateMasterDataItem']);

        Route::get('/fa-subclass-type-codes', [FaSubclassTypeCodeController::class, 'index']);
        Route::post('/fa-subclass-type-codes', [FaSubclassTypeCodeController::class, 'store']);
        Route::patch('/fa-subclass-type-codes/{code}', [FaSubclassTypeCodeController::class, 'update']);
        Route::delete('/fa-subclass-type-codes/{code}', [FaSubclassTypeCodeController::class, 'destroy']);

        Route::get('/api-clients', [ApiClientController::class, 'index']);
        Route::post('/api-clients', [ApiClientController::class, 'store']);
        Route::get('/api-clients/{client}', [ApiClientController::class, 'show']);
        Route::delete('/api-clients/{client}', [ApiClientController::class, 'destroy']);

        Route::get('/usage-reading-types', [MasterDataController::class, 'indexUsageReadingTypes']);
        Route::post('/usage-reading-types', [MasterDataController::class, 'storeUsageReadingType']);
        Route::patch('/usage-reading-types/{type}', [MasterDataController::class, 'updateUsageReadingType']);

        Route::get('/wo-forms/templates', [FormTemplateController::class, 'index']);
        Route::post('/wo-forms/templates', [FormTemplateController::class, 'store']);
        Route::get('/wo-forms/templates/{template}', [FormTemplateController::class, 'show']);
        Route::patch('/wo-forms/templates/{template}', [FormTemplateController::class, 'update']);
        Route::post('/wo-forms/templates/{template}/deactivate', [FormTemplateController::class, 'deactivate']);
        Route::post('/wo-forms/templates/{template}/reactivate', [FormTemplateController::class, 'reactivate']);
        Route::post('/wo-forms/templates/{template}/fields', [FormTemplateController::class, 'addField']);
        Route::patch('/wo-forms/templates/{template}/fields/{field}', [FormTemplateController::class, 'updateField']);
        Route::delete('/wo-forms/templates/{template}/fields/{field}', [FormTemplateController::class, 'deleteField']);
        Route::post('/wo-forms/templates/{template}/fields/reorder', [FormTemplateController::class, 'reorderFields']);
    });

    Route::get('/assets/by-tag', [AssetController::class, 'byTag']);
    Route::get('/assets', [AssetController::class, 'index']);
    Route::post('/assets', [AssetController::class, 'store']);
    Route::get('/assets/{asset}', [AssetController::class, 'show']);
    Route::patch('/assets/{asset}', [AssetController::class, 'update']);
    Route::post('/assets/{asset}/suggest-tag', [AssetController::class, 'suggestTag']);
    Route::get('/assets/{asset}/meter-readings', [AssetController::class, 'meterReadings']);
    Route::get('/assets/{asset}/location-history', [AssetController::class, 'locationHistory']);
    Route::get('/assets/{asset}/maintenance-history', [AssetController::class, 'maintenanceHistory']);
    Route::get('/assets/{asset}/attachments', [AttachmentController::class, 'indexForAsset']);
    Route::post('/assets/{asset}/attachments', [AttachmentController::class, 'uploadForAsset']);

    Route::post('/assets/{asset}/location', [AssetLocationController::class, 'update']);
    Route::post('/assets/{asset}/book', [AssetBookingController::class, 'book']);
    Route::post('/assets/{asset}/unbook', [AssetBookingController::class, 'unbook']);
    Route::post('/assets/{asset}/meter-readings', [AssetMeterReadingController::class, 'store']);
    Route::post('/assets/{asset}/meter-readings/{reading}/confirm', [AssetMeterReadingController::class, 'confirm']);
    Route::patch('/assets/{asset}/meter-readings/{reading}', [AssetMeterReadingController::class, 'update']);
    Route::delete('/assets/{asset}/meter-readings/{reading}', [AssetMeterReadingController::class, 'delete']);

    Route::get('/assets/{asset}/pm-assignments', [AssetPmAssignmentController::class, 'index']);
    Route::post('/assets/{asset}/pm-assignments', [AssetPmAssignmentController::class, 'store']);
    Route::get('/assets/{asset}/pm-assignments/{assignment}', [AssetPmAssignmentController::class, 'show']);
    Route::post('/assets/{asset}/pm-assignments/{assignment}/deactivate', [AssetPmAssignmentController::class, 'deactivate']);
    Route::post('/assets/{asset}/pm-assignments/{assignment}/reactivate', [AssetPmAssignmentController::class, 'reactivate']);
    Route::post('/assets/{asset}/pm-assignments/{assignment}/evaluate', [AssetPmAssignmentController::class, 'evaluate']);

    Route::get('/parts', [PartController::class, 'index']);
    Route::get('/parts/{part}', [PartController::class, 'show']);
    Route::patch('/parts/{part}', [PartController::class, 'update']);
    Route::get('/parts/{part}/attachments', [AttachmentController::class, 'indexForPart']);
    Route::post('/parts/{part}/attachments', [AttachmentController::class, 'uploadForPart']);

    Route::get('/maintenance-requests', [MaintenanceRequestController::class, 'index']);
    Route::post('/maintenance-requests/corrective', [MaintenanceRequestController::class, 'storeCorrective']);
    Route::get('/maintenance-requests/{maintenanceRequest}', [MaintenanceRequestController::class, 'show']);
    Route::patch('/maintenance-requests/{maintenanceRequest}', [MaintenanceRequestController::class, 'update']);
    Route::post('/maintenance-requests/{maintenanceRequest}/approve', [MaintenanceRequestController::class, 'approve']);
    Route::post('/maintenance-requests/{maintenanceRequest}/reject', [MaintenanceRequestController::class, 'reject']);
    Route::post('/maintenance-requests/{maintenanceRequest}/cancel', [MaintenanceRequestController::class, 'cancel']);
    Route::get('/maintenance-requests/{maintenanceRequest}/attachments', [AttachmentController::class, 'indexForMaintenanceRequest']);
    Route::post('/maintenance-requests/{maintenanceRequest}/attachments', [AttachmentController::class, 'uploadForMaintenanceRequest']);

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
    Route::post('/work-orders/{workOrder}/asset-status', [WorkOrderController::class, 'setAssetStatus']);
    Route::get('/work-orders/{workOrder}/form', [WorkOrderController::class, 'showForm']);
    Route::patch('/work-orders/{workOrder}/form/fields/{field}', [WorkOrderController::class, 'updateFormField']);
    Route::post('/work-orders/{workOrder}/form/sync', [WorkOrderController::class, 'syncForm']);
    Route::post('/work-orders/{workOrder}/form/defer-sync', [WorkOrderController::class, 'deferFormSync']);
    Route::get('/work-orders/{workOrder}/attachments', [AttachmentController::class, 'indexForWorkOrder']);
    Route::post('/work-orders/{workOrder}/attachments', [AttachmentController::class, 'uploadForWorkOrder']);

    Route::get('/pm-rules', [PmRuleController::class, 'index']);
    Route::post('/pm-rules', [PmRuleController::class, 'store']);
    Route::get('/pm-rules/{pmRule}', [PmRuleController::class, 'show']);
    Route::patch('/pm-rules/{pmRule}', [PmRuleController::class, 'update']);
    Route::post('/pm-rules/{pmRule}/deactivate', [PmRuleController::class, 'deactivate']);
    Route::post('/pm-rules/{pmRule}/reactivate', [PmRuleController::class, 'reactivate']);
    Route::get('/pm-rules/{pmRule}/assignments', [PmRuleController::class, 'assignments']);
    Route::post('/pm-rules/evaluate-all', [AssetPmAssignmentController::class, 'evaluateAll']);

    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download']);
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'softDelete']);
});
