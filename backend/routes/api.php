<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CrossNominationController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\LeagueController;
use App\Http\Controllers\Api\LogoController;
use App\Http\Controllers\Api\MatchImportController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\NoticeController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\PersonController;
use App\Http\Controllers\Api\PollController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\StatisticsController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TenantMeController;
use App\Http\Controllers\Api\TrainingGroupController;
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

    Route::get('push/key', [PushController::class, 'key']);
    Route::post('push/subscriptions', [PushController::class, 'subscribe']);
    Route::delete('push/subscriptions', [PushController::class, 'unsubscribe']);
});

Route::prefix('t/{tenant}')->middleware('tenant')->group(function () {
    // Public: department info, login, invitation links.
    Route::get('/', [TenantController::class, 'show']);
    Route::post('auth/login', [AuthController::class, 'requestLink']);
    Route::get('invitations/{token}', [InvitationController::class, 'showPublic']);
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept']);
    Route::get('logo', [LogoController::class, 'showTenantLogo']);
    Route::get('calendar/{token}', [CalendarController::class, 'feed'])->middleware('throttle:60,1');

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

        Route::get('leagues', [LeagueController::class, 'index']);
        Route::post('leagues', [LeagueController::class, 'store']);
        Route::patch('leagues/{league}', [LeagueController::class, 'update']);
        Route::delete('leagues/{league}', [LeagueController::class, 'destroy']);

        Route::get('teams/{team}/cross-nominations', [CrossNominationController::class, 'index']);
        Route::get('teams/{team}/cross-nomination-candidates', [CrossNominationController::class, 'candidates']);
        Route::post('teams/{team}/cross-nominations', [CrossNominationController::class, 'store']);
        Route::patch('cross-nominations/{nomination}', [CrossNominationController::class, 'update']);
        Route::delete('cross-nominations/{nomination}', [CrossNominationController::class, 'destroy']);

        Route::post('teams/{team}/match-import/preview', [MatchImportController::class, 'preview']);
        Route::post('teams/{team}/match-import', [MatchImportController::class, 'store']);

        Route::get('teams/{team}/statistics', [StatisticsController::class, 'team']);
        Route::get('teams/{team}/statistics/tasks', [StatisticsController::class, 'tasks']);
        Route::get('teams/{team}/statistics/persons/{person}', [StatisticsController::class, 'person']);

        Route::get('teams/{team}/training-groups', [TrainingGroupController::class, 'index']);
        Route::post('teams/{team}/training-groups', [TrainingGroupController::class, 'store']);
        Route::patch('training-groups/{group}', [TrainingGroupController::class, 'update']);
        Route::delete('training-groups/{group}', [TrainingGroupController::class, 'destroy']);

        Route::get('persons', [PersonController::class, 'index']);
        Route::post('persons', [PersonController::class, 'store']);
        Route::get('persons/{person}', [PersonController::class, 'show']);
        Route::patch('persons/{person}', [PersonController::class, 'update']);
        Route::post('persons/{person}/avatar', [PersonController::class, 'uploadAvatar']);
        Route::delete('persons/{person}/avatar', [PersonController::class, 'deleteAvatar']);
        Route::put('persons/{person}/referee-license', [PersonController::class, 'updateRefereeLicense']);
        Route::delete('persons/{person}/referee-license', [PersonController::class, 'deleteRefereeLicense']);
        Route::put('persons/{person}/minor-permissions', [PersonController::class, 'updateMinorPermissions']);
        Route::post('persons/{person}/invitations', [InvitationController::class, 'storeForPerson']);

        Route::get('members', [MemberController::class, 'index']);
        Route::patch('members/{member}', [MemberController::class, 'update']);

        Route::get('files/{file}', FileController::class);

        Route::get('events', [EventController::class, 'index']);
        Route::post('events', [EventController::class, 'store']);
        Route::get('events/{event}', [EventController::class, 'show']);
        Route::patch('events/{event}', [EventController::class, 'update']);
        Route::post('events/{event}/cancel', [EventController::class, 'cancel']);
        Route::post('events/{event}/end-series', [EventController::class, 'endSeries']);
        Route::post('events/{event}/responses', [EventController::class, 'respond']);

        Route::get('chats', [ChatController::class, 'index']);
        Route::post('chats', [ChatController::class, 'store']);
        Route::get('chats/{chat}', [ChatController::class, 'show']);
        Route::patch('chats/{chat}', [ChatController::class, 'update']);
        Route::delete('chats/{chat}', [ChatController::class, 'destroy']);
        Route::post('chats/{chat}/topics', [ChatController::class, 'storeTopic']);
        Route::patch('chat-topics/{topic}', [ChatController::class, 'updateTopic']);
        Route::get('chats/{chat}/messages', [ChatController::class, 'messages'])->middleware('throttle:120,1');
        Route::post('chats/{chat}/messages', [ChatController::class, 'send'])->middleware('throttle:30,1');
        Route::post('chats/{chat}/attachments', [ChatController::class, 'upload'])->middleware('throttle:60,1');
        Route::get('chat-attachments/{attachment}', [ChatController::class, 'attachment']);
        Route::patch('chat-messages/{message}', [ChatController::class, 'updateMessage']);
        Route::delete('chat-messages/{message}', [ChatController::class, 'destroyMessage']);

        Route::get('polls', [PollController::class, 'index']);
        Route::post('polls', [PollController::class, 'store']);
        Route::get('polls/{poll}', [PollController::class, 'show']);
        Route::post('polls/{poll}/votes', [PollController::class, 'vote']);
        Route::delete('polls/{poll}/votes/{person}', [PollController::class, 'withdraw']);
        Route::post('polls/{poll}/close', [PollController::class, 'close']);
        Route::delete('polls/{poll}', [PollController::class, 'destroy']);

        Route::get('tasks', [TaskController::class, 'index']);
        Route::post('events/{event}/tasks', [TaskController::class, 'store']);
        Route::patch('tasks/{task}', [TaskController::class, 'update']);
        Route::delete('tasks/{task}', [TaskController::class, 'destroy']);
        Route::post('tasks/{task}/assignments', [TaskController::class, 'take']);
        Route::delete('tasks/{task}/assignments/{person}', [TaskController::class, 'release']);

        Route::get('notices', [NoticeController::class, 'index']);
        Route::post('notices', [NoticeController::class, 'store']);
        Route::delete('notices/{notice}', [NoticeController::class, 'destroy']);

        Route::post('logo', [LogoController::class, 'uploadTenantLogo']);
        Route::post('teams/{team}/logo', [LogoController::class, 'uploadTeamLogo']);
        Route::get('opponents', [LogoController::class, 'opponents']);
        Route::post('opponents', [LogoController::class, 'storeOpponent']);
        Route::post('opponents/{opponent}/logo', [LogoController::class, 'uploadOpponentLogo']);

        Route::get('notification-preferences', [NotificationPreferenceController::class, 'index']);
        Route::put('notification-preferences', [NotificationPreferenceController::class, 'update']);

        Route::get('calendar', [CalendarController::class, 'show']);
        Route::post('calendar/reset', [CalendarController::class, 'reset']);
    });
});
