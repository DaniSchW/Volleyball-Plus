<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Team;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Chat\AttachmentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Document storage. "For everybody" (team null) – every member reads,
 * admins upload; per team – who sees the team reads, its trainers,
 * organizers and admins upload. Optionally sorted into folders.
 */
class DocumentController extends Controller
{
    public function __construct(private CurrentMember $current, private Permissions $permissions, private AttachmentStore $files) {}

    public function index(Request $request): JsonResponse
    {
        $member = $this->current->get();
        $teams = Team::query()->whereNull('archived_at')->orderBy('name')->get()->filter(fn (Team $t) => $this->permissions->canSeeTeam($member, $t))->values();
        $teamId = $request->query('team');
        abort_if($teamId !== null && ! $teams->contains('id', $teamId), 403);

        $documents = Document::query()->with(['file', 'team'])
            ->where(fn ($q) => $q->whereNull('team_id')->orWhereIn('team_id', $teams->pluck('id')))
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->orderBy('folder')->orderBy('title')->get();

        return response()->json(['data' => [
            'can_upload' => $this->permissions->canManageDocuments($member, null),
            'teams' => $teams->map(fn (Team $t) => ['id' => $t->id, 'name' => $t->name, 'can_upload' => $this->permissions->canManageDocuments($member, $t)])->all(),
            'documents' => $documents->map(fn (Document $d) => $this->present($d))->values()->all(),
            'max_mb' => (int) floor(AttachmentStore::maxBytes() / 1048576),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $member = $this->current->get();
        $data = $this->validated($request, true);
        $team = $this->team($data['team_id'] ?? null);
        abort_unless($this->permissions->canManageDocuments($member, $team), 403);

        [$file, $name] = $this->files->storeFile($request->file('file'), $member, 'document');
        $document = Document::create([
            'team_id' => $team?->id,
            'folder' => $this->clean($data['folder'] ?? null),
            'title' => $this->clean($data['title'] ?? null) ?? pathinfo($name, PATHINFO_FILENAME),
            'description' => $this->clean($data['description'] ?? null),
            'file_id' => $file->id,
            'name' => $name,
            'uploaded_by' => $member->id,
        ]);

        return response()->json(['data' => $this->present($document->load(['file', 'team']))], 201);
    }

    /** Rename, re-sort or move (both places must be manageable). */
    public function update(Request $request, Document $document): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->permissions->canManageDocuments($member, $document->team), 403);
        $data = $this->validated($request, false);
        $team = array_key_exists('team_id', $data) ? $this->team($data['team_id']) : $document->team;
        abort_unless($this->permissions->canManageDocuments($member, $team), 403);

        $document->update([
            'team_id' => $team?->id,
            'folder' => array_key_exists('folder', $data) ? $this->clean($data['folder']) : $document->folder,
            'title' => $this->clean($data['title'] ?? null) ?? $document->title,
            'description' => array_key_exists('description', $data) ? $this->clean($data['description']) : $document->description,
        ]);

        return response()->json(['data' => $this->present($document->load(['file', 'team']))]);
    }

    public function destroy(Document $document): JsonResponse
    {
        abort_unless($this->permissions->canManageDocuments($this->current->get(), $document->team), 403);
        $file = $document->file;
        DB::transaction(function () use ($document, $file) {
            $document->delete();
            $file?->delete();
        });
        if ($file !== null) {
            Storage::disk('local')->delete($file->storage_key);
        }

        return response()->json(['message' => 'ok']);
    }

    /** PDFs and pictures open in the browser, everything else downloads. */
    public function file(Document $document): BinaryFileResponse
    {
        abort_unless($this->permissions->canSeeDocument($this->current->get(), $document), 403);
        $file = $document->file;
        $disk = Storage::disk('local');
        abort_unless($file !== null && $disk->exists($file->storage_key), 404);
        $inline = $file->mime_type === 'application/pdf' || in_array($file->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);

        $response = response()->file($disk->path($file->storage_key), [
            'Content-Type' => $file->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setContentDisposition($inline ? 'inline' : 'attachment', $document->name, Str::ascii($document->name));

        return $response;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'file' => [$creating ? 'required' : 'prohibited', 'file'],
            'team_id' => ['sometimes', 'nullable', 'string'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:80'],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
    }

    private function team(?string $id): ?Team
    {
        if ($id === null || $id === '') {
            return null;
        }
        $team = Team::query()->whereKey($id)->whereNull('archived_at')->first();
        abort_if($team === null, 422, __('events.teams_invalid'));

        return $team;
    }

    private function clean(?string $value): ?string
    {
        return trim((string) $value) ?: null;
    }

    /** @return array<string, mixed> */
    private function present(Document $document): array
    {
        $member = $this->current->get();

        return [
            'id' => $document->id,
            'title' => $document->title,
            'description' => $document->description,
            'folder' => $document->folder,
            'team' => $document->team ? ['id' => $document->team->id, 'name' => $document->team->name] : null,
            'name' => $document->name,
            'mime_type' => $document->file?->mime_type,
            'size_bytes' => (int) $document->file?->size_bytes,
            'uploaded_at' => $document->created_at?->toIso8601ZuluString(),
            'url' => '/api/v1/t/'.$member->tenant->slug.'/documents/'.$document->id.'/file',
            'can_manage' => $this->permissions->canManageDocuments($member, $document->team),
        ];
    }
}
