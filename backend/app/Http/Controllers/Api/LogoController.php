<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Opponent;
use App\Models\StoredFile;
use App\Models\Team;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Files\ImageStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Three logo levels: department (admin), team (admin/trainer) and opponent
 * club (whoever manages events).
 */
class LogoController extends Controller
{
    public function __construct(private CurrentMember $current, private Permissions $permissions, private ImageStore $images) {}

    /** Department logo – public, it is shown on the login page. */
    public function showTenantLogo(TenantContext $tenants): Response
    {
        $file = $tenants->get()->logo_file_id ? StoredFile::query()->find($tenants->get()->logo_file_id) : null;
        abort_if($file === null || ! Storage::disk('local')->exists($file->storage_key), 404);

        return response(Storage::disk('local')->get($file->storage_key), 200, [
            'Content-Type' => $file->mime_type,
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function uploadTenantLogo(Request $request, TenantContext $tenants): JsonResponse
    {
        abort_unless($this->permissions->isAdmin($this->current->get()), 403);
        $tenant = $tenants->get();

        $previous = $tenant->logo_file_id;
        $tenant->forceFill(['logo_file_id' => $this->store($request)->id])->save();
        $this->forget($previous);

        return response()->json(['data' => ['logo_url' => '/api/v1/t/'.$tenant->slug.'/logo']]);
    }

    public function uploadTeamLogo(Request $request, Team $team): JsonResponse
    {
        abort_unless($this->permissions->canManageTeam($this->current->get(), $team), 403);

        $previous = $team->logo_file_id;
        $team->forceFill(['logo_file_id' => $this->store($request)->id])->save();
        $this->forget($previous);

        return response()->json(['data' => ['logo_url' => $this->url($team->logo_file_id)]]);
    }

    public function uploadOpponentLogo(Request $request, Opponent $opponent): JsonResponse
    {
        abort_unless($this->mayManageOpponents(), 403);

        $previous = $opponent->logo_file_id;
        $opponent->forceFill(['logo_file_id' => $this->store($request)->id])->save();
        $this->forget($previous);

        return response()->json(['data' => ['logo_url' => $this->url($opponent->logo_file_id)]]);
    }

    public function opponents(): JsonResponse
    {
        return response()->json(['data' => Opponent::query()->orderBy('name')->get()->map(fn (Opponent $o) => [
            'id' => $o->id,
            'name' => $o->name,
            'logo_url' => $o->logo_file_id ? $this->url($o->logo_file_id) : null,
        ])->all()]);
    }

    public function storeOpponent(Request $request): JsonResponse
    {
        abort_unless($this->mayManageOpponents(), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $opponent = Opponent::query()->firstOrCreate(['name' => trim($data['name'])]);

        return response()->json(['data' => ['id' => $opponent->id, 'name' => $opponent->name, 'logo_url' => $opponent->logo_file_id ? $this->url($opponent->logo_file_id) : null]], 201);
    }

    private function store(Request $request): StoredFile
    {
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpeg,png,webp,gif', 'max:8192']]);

        return $this->images->store($request->file('image'), 'logo', $this->current->get()->id);
    }

    private function forget(?string $fileId): void
    {
        $file = $fileId ? StoredFile::query()->find($fileId) : null;
        if ($file !== null) {
            $this->images->delete($file);
        }
    }

    private function mayManageOpponents(): bool
    {
        $member = $this->current->get();

        return $this->permissions->isAdmin($member)
            || $this->permissions->ownMemberships($member)->contains(fn ($m) => in_array($m->role->value, ['trainer', 'organizer'], true));
    }

    private function url(string $fileId): string
    {
        return '/api/v1/t/'.$this->current->get()->tenant->slug.'/files/'.$fileId;
    }
}
