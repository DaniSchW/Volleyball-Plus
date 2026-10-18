<?php

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

/*
| Web installer. Registered outside the "web" middleware group: before the
| installation there is no application key, so no session or encrypted
| cookies can be used.
*/

Route::get('install', [InstallController::class, 'show']);
Route::post('install', [InstallController::class, 'store']);
