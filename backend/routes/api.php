<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\TenantController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class);

Route::prefix('t/{tenant}')->middleware('tenant')->group(function () {
    Route::get('/', [TenantController::class, 'show']);
});
