<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\League;
use App\Models\Team;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Teams\LeagueTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * League tree: everybody can read it (team settings), admins maintain it.
 */
class LeagueController extends Controller
{
    public function __construct(private CurrentMember $current, private Permissions $permissions, private LeagueTree $tree) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->tree->flat()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin();
        $this->tree->ensureSeeded();
        $data = $request->validate($this->rules(required: true));

        $parent = isset($data['parent_id']) ? League::query()->findOrFail($data['parent_id']) : null;
        League::create([
            'parent_id' => $parent?->id,
            'name' => $data['name'],
            // A sub-league (e.g. a regional group) is on its parent's level unless set.
            'level' => $data['level'] ?? $parent?->level ?? 1,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return response()->json(['data' => $this->tree->flat()], 201);
    }

    public function update(Request $request, League $league): JsonResponse
    {
        $this->authorizeAdmin();
        $data = $request->validate($this->rules(required: false));

        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
            $parent = League::query()->findOrFail($data['parent_id']);
            if ($this->isSelfOrBelow($parent, $league)) {
                throw ValidationException::withMessages(['parent_id' => __('teams.league_parent_invalid')]);
            }
        }

        $league->fill($data)->save();

        return response()->json(['data' => $this->tree->flat()]);
    }

    public function destroy(League $league): JsonResponse
    {
        $this->authorizeAdmin();

        if ($league->children()->exists() || Team::query()->where('league_id', $league->id)->whereNull('archived_at')->exists()) {
            throw ValidationException::withMessages(['league' => __('teams.league_in_use')]);
        }
        // Archived teams keep no reference to a deleted league.
        Team::query()->where('league_id', $league->id)->update(['league_id' => null]);
        $league->delete();

        return response()->json(['data' => $this->tree->flat()]);
    }

    private function authorizeAdmin(): void
    {
        abort_unless($this->permissions->isAdmin($this->current->get()), 403);
    }

    private function isSelfOrBelow(League $candidate, League $league): bool
    {
        for ($node = $candidate; $node !== null; $node = $node->parent) {
            if ($node->id === $league->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $required): array
    {
        $tenantId = $this->current->get()->tenant_id;

        return [
            'name' => [$required ? 'required' : 'sometimes', 'string', 'max:80'],
            'parent_id' => ['sometimes', 'nullable', 'string', Rule::exists('leagues', 'id')->where('tenant_id', $tenantId)],
            'level' => ['sometimes', 'integer', 'between:1,30'],
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],
        ];
    }
}
