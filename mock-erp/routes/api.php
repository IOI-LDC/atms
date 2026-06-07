<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\PartController;
use Illuminate\Support\Facades\Route;

Route::middleware([\App\Http\Middleware\RequireServiceApiKey::class])->group(function () {
    Route::get('/assets', [AssetController::class, 'index']);
    Route::get('/parts', [PartController::class, 'index']);
});
