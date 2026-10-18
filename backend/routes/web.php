<?php

use App\Http\Controllers\CronController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// Fallback for hosting without a cron job: an external cron service calls this URL every minute.
Route::get('cron/{token}', CronController::class)->middleware('throttle:6,1');

// Every other path is handled by the PWA (built into public/app.html).
Route::fallback(SpaController::class);
