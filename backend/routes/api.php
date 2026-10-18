<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\PersonController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TenantMeController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class);

// Magic link redemption (login or invitation confirmation).
Route::post('auth/consume', [AuthController::class, 'consume'])->middleware('throttle:20,1');

// The account across departments.
Route::middleware('auth')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AccountController::class, 'show']);
    Route::patch('me', [AccountController::class, 'update']);
    Route::get('me/sessions', [AccountController::class, 'sessions']);
    Route::delete('me/sessions/{session}', [AccountController::class, 'revokeSession']);
});

Route::prefix('t/{tenant}')->middleware('tenant')->group(function () {
    // Public: department info, login, invitation links.
    Route::get('/', [TenantController::class, 'show']);
    Route::post('auth/login', [AuthController::class, 'requestLink']);
    Route::get('invitations/{token}', [InvitationController::class, 'showPublic']);
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept']);

    // Members of the department.
    Route::middleware('member')->group(function () {
        Route::get('me', TenantMeController::class);

        Route::get('teams', [TeamController::class, 'index']);
        Route::post('teams', [TeamController::class, 'store']);
        Route::get('teams/{team}', [TeamController::class, 'show']);
        Route::patch('teams/{team}', [TeamController::class, 'update']);
        Route::delete('teams/{team}', [TeamController::class, 'destroy']);
        Route::get('teams/{team}/members', [TeamController::class, 'members']);
        Route::post('teams/{team}/members', [TeamController::class, 'addMember']);
        Route::delete('teams/{team}/members/{membership}', [TeamController::class, 'removeMember']);
        Route::get('teams/{team}/invitations', [InvitationController::class, 'index']);
        Route::post('teams/{team}/invitations', [InvitationController::class, 'store']);
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy']);

        Route::get('persons', [PersonController::class, 'index']);
        Route::post('persons', [PersonController::class, 'store']);
        Route::get('persons/{person}', [PersonController::class, 'show']);
        Route::patch('persons/{person}', [PersonController::class, 'update']);
        Route::post('persons/{person}/avatar', [PersonController::class, 'uploadAvatar']);
        Route::delete('persons/{person}/avatar', [PersonController::class, 'deleteAvatar']);
        Route::put('persons/{person}/minor-permissions', [PersonController::class, 'updateMinorPermissions']);
        Route::post('persons/{person}/invitations', [InvitationController::class, 'storeForPerson']);

        Route::get('members', [MemberController::class, 'index']);
        Route::patch('members/{member}', [MemberController::class, 'update']);

        Route::get('files/{file}', FileController::class);
    });
});
