<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Cash\CashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Admins: what changed in the club in the last 7 days, and by whom.
 */
class ActivityController extends Controller
{
    public function __invoke(CurrentMember $current, Permissions $permissions, CashService $cash): JsonResponse
    {
        abort_unless($permissions->isAdmin($current->get()), 403);

        $entries = ActivityLog::query()
            ->where('created_at', '>=', Carbon::now()->subDays(ActivityLog::KEEP_DAYS))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(500)->get();
        $names = $cash->memberNames($entries->pluck('actor_id'));

        return response()->json(['data' => $entries->map(fn (ActivityLog $e) => [
            'id' => $e->id,
            'action' => $e->action,
            'type' => $e->subject_type,
            'label' => $e->label,
            'actor' => $e->actor_id ? ($names[$e->actor_id] ?? null) : null,
            'by_system' => $e->actor_id === null,
            'created_at' => $e->created_at?->toIso8601String(),
        ])->values()->all()]);
    }
}
