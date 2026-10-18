<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatTopic;
use App\Models\TenantMember;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Chat\AttachmentStore;
use App\Support\Chat\ChatAccess;
use App\Support\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Team chats and the admins' custom chats. An open chat polls
 * GET …/messages?after=<last id>&since=<time> every few seconds.
 */
class ChatController extends Controller
{
    public function __construct(
        private CurrentMember $current,
        private Permissions $permissions,
        private ChatAccess $access,
        private ChatService $chats,
    ) {}

    public function index(): JsonResponse
    {
        $member = $this->current->get();
        $admin = $this->permissions->isAdmin($member);
        $teamIds = $this->access->teamIdsFor($member);
        $this->chats->ensureTeamChats($teamIds);

        $chats = Chat::query()->with('team')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('kind', Chat::KIND_TEAM)->whereIn('team_id', $teamIds))
                ->orWhereIn('id', ChatMember::query()->where('tenant_member_id', $member->id)->select('chat_id'))
                ->when($admin, fn ($q) => $q->orWhere('kind', Chat::KIND_CUSTOM)))
            ->get()
            ->filter(fn (Chat $chat) => $chat->kind === Chat::KIND_CUSTOM || ($chat->team && $chat->team->archived_at === null))
            ->filter(fn (Chat $chat) => $this->access->canRead($member, $chat))
            ->values();

        $unread = $this->chats->unread($chats, $member);
        $last = ChatMessage::query()->whereIn('id', ChatMessage::query()->whereIn('chat_id', $chats->pluck('id'))
            ->selectRaw('max(id)')->groupBy('chat_id'))->get()->keyBy('chat_id');
        $names = $last->groupBy('chat_id')->map(fn ($m, $chatId) => $this->chats->authorNames($chats->firstWhere('id', $chatId), $m->pluck('author_id')));

        $rows = $chats->map(fn (Chat $chat) => [
            ...$this->summary($chat, $member),
            'unread' => $unread[$chat->id]['total'] ?? 0,
            'last_message' => ($m = $last->get($chat->id)) ? [
                'author' => $names[$chat->id][$m->author_id] ?? null,
                'body' => $m->deleted_at ? null : ($m->body !== '' ? Str::limit($m->body, 80) : $this->chats->attachmentLabel($m)),
                'created_at' => $m->created_at->toIso8601ZuluString(),
            ] : null,
        ])->sortByDesc(fn ($row) => $row['last_message']['created_at'] ?? '')->values();

        return response()->json(['data' => $rows->all()]);
    }

    public function show(Chat $chat): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->access->canRead($member, $chat), 403, __('chat.not_allowed'));
        $unread = $this->chats->unread(collect([$chat]), $member)[$chat->id];

        return response()->json(['data' => $this->summary($chat, $member) + [
            'topics' => $chat->hasTopics() ? $chat->topics->map(fn (ChatTopic $t) => [
                'id' => $t->id, 'name' => $t->name, 'unread' => $unread['topics'][$t->id] ?? 0,
            ])->values()->all() : [],
            'members' => $this->memberList($chat),
            'upload' => [
                'max_bytes' => AttachmentStore::maxBytes(),
                'per_message' => (int) config('security.chat_upload.per_message'),
                'extensions' => array_keys(AttachmentStore::TYPES),
            ],
        ]]);
    }

    /** Admins: chat with freely chosen members. */
    public function store(Request $request): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->permissions->isAdmin($member), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'mode' => ['sometimes', Rule::in([Chat::MODE_FLAT, Chat::MODE_TOPICS])],
            'member_ids' => ['required', 'array', 'min:1', 'max:200'],
            'member_ids.*' => ['string', Rule::exists('tenant_members', 'id')->where('tenant_id', $member->tenant_id)],
        ]);

        $chat = DB::transaction(function () use ($data, $member) {
            $chat = Chat::create(['kind' => Chat::KIND_CUSTOM, 'name' => $data['name'], 'created_by' => $member->id]);
            $this->syncMembers($chat, collect($data['member_ids'])->push($member->id), $member->id);
            if (($data['mode'] ?? Chat::MODE_FLAT) === Chat::MODE_TOPICS) {
                $this->chats->setMode($chat, Chat::MODE_TOPICS);
            }

            return $chat;
        });

        return $this->show($chat->refresh());
    }

    public function update(Request $request, Chat $chat): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->access->canModerate($member, $chat), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'mode' => ['sometimes', Rule::in([Chat::MODE_FLAT, Chat::MODE_TOPICS])],
            'member_ids' => ['sometimes', 'array', 'min:1', 'max:200'],
            'member_ids.*' => ['string', Rule::exists('tenant_members', 'id')->where('tenant_id', $member->tenant_id)],
        ]);

        if (isset($data['name']) && $chat->kind === Chat::KIND_CUSTOM) {
            $chat->forceFill(['name' => $data['name']])->save();
        }
        if (isset($data['mode'])) {
            $this->chats->setMode($chat, $data['mode']);
        }
        if (isset($data['member_ids']) && $chat->kind === Chat::KIND_CUSTOM) {
            $this->syncMembers($chat, collect($data['member_ids']), $chat->created_by);
        }

        return $this->show($chat->refresh());
    }

    public function destroy(Chat $chat): JsonResponse
    {
        abort_unless($chat->kind === Chat::KIND_CUSTOM && $this->permissions->isAdmin($this->current->get()), 403);

        DB::transaction(function () use ($chat) {
            DB::table('chat_read_states')->where('chat_id', $chat->id)->delete();
            ChatAttachment::query()->with('file')->where('chat_id', $chat->id)->get()->each(fn (ChatAttachment $a) => app(AttachmentStore::class)->delete($a));
            ChatMessage::query()->where('chat_id', $chat->id)->delete();
            ChatTopic::query()->where('chat_id', $chat->id)->delete();
            ChatMember::query()->where('chat_id', $chat->id)->delete();
            $chat->delete();
        });

        return response()->json(['message' => 'ok']);
    }

    public function storeTopic(Request $request, Chat $chat): JsonResponse
    {
        abort_unless($this->access->canModerate($this->current->get(), $chat) && $chat->hasTopics(), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        ChatTopic::create(['chat_id' => $chat->id, 'name' => $data['name'], 'sort_order' => (int) ChatTopic::query()->where('chat_id', $chat->id)->max('sort_order') + 1]);

        return $this->show($chat);
    }

    public function updateTopic(Request $request, ChatTopic $topic): JsonResponse
    {
        $chat = Chat::query()->findOrFail($topic->chat_id);
        abort_unless($this->access->canModerate($this->current->get(), $chat), 403);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:60'], 'archived' => ['sometimes', 'boolean']]);

        if (isset($data['name'])) {
            $topic->name = $data['name'];
        }
        if (isset($data['archived'])) {
            $topic->archived_at = $data['archived'] ? Carbon::now() : null;
        }
        $topic->save();

        return $this->show($chat);
    }

    /** Newest page, older pages (?before=), or new and changed messages for polling (?after=&since=). */
    public function messages(Request $request, Chat $chat): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->access->canRead($member, $chat), 403, __('chat.not_allowed'));
        $data = $request->validate([
            'topic' => ['nullable', 'string'],
            'before' => ['nullable', 'string', 'size:26'],
            'after' => ['nullable', 'string', 'size:26'],
            'since' => ['nullable', 'date'],
        ]);
        $topic = $data['topic'] ?? null;
        $now = Carbon::now();

        $messages = $this->chats->messages($chat, $topic, $data['before'] ?? null, $data['after'] ?? null);
        $changed = isset($data['after'], $data['since'])
            ? $this->chats->changedSince($chat, $topic, Carbon::parse($data['since'])->subSecond(), $data['after'])
            : collect();
        $latest = $messages->last()?->id ?? ($data['after'] ?? null);
        if (! isset($data['before'])) {
            $this->chats->markRead($chat, $member, $topic, $latest);
        }

        return response()->json([
            'data' => $this->presentMessages($chat, $messages, $member),
            'changed' => $this->presentMessages($chat, $changed, $member),
            'has_more' => ! isset($data['after']) && $messages->count() === ChatService::PAGE,
            'server_time' => $now->toIso8601ZuluString(),
        ]);
    }

    public function send(Request $request, Chat $chat): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->access->canRead($member, $chat), 403, __('chat.not_allowed'));
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'topic_id' => ['nullable', 'string'],
            'reply_to_id' => ['nullable', 'string', 'size:26'],
            'attachment_ids' => ['sometimes', 'array', 'max:'.config('security.chat_upload.per_message')],
            'attachment_ids.*' => ['string', 'size:26'],
        ]);

        $message = $this->chats->send($chat, $member, trim((string) ($data['body'] ?? '')), $data['topic_id'] ?? null, $data['reply_to_id'] ?? null, $data['attachment_ids'] ?? []);

        return response()->json(['data' => $this->presentMessages($chat, collect([$message]), $member)[0]], 201);
    }

    /** Upload one file; it is sent later with a message (attachment_ids). */
    public function upload(Request $request, Chat $chat, AttachmentStore $store): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->access->canRead($member, $chat), 403, __('chat.not_allowed'));
        $request->validate(['file' => ['required', 'file']]);

        $attachment = $store->store($chat, $request->file('file'), $member);

        return response()->json(['data' => $this->presentAttachment($attachment->load('file'))], 201);
    }

    /**
     * Pictures, videos and PDFs are shown in the browser, other documents
     * downloaded. Byte ranges are supported, so videos can be scrubbed.
     */
    public function attachment(ChatAttachment $attachment): BinaryFileResponse
    {
        $member = $this->current->get();
        $chat = Chat::query()->findOrFail($attachment->chat_id);
        abort_unless($this->access->canRead($member, $chat), 403, __('chat.not_allowed'));
        abort_if($attachment->message_id === null && $attachment->uploaded_by !== $member->id, 404);

        $file = $attachment->file;
        $disk = Storage::disk('local');
        abort_unless($file !== null && $disk->exists($file->storage_key), 404);
        $inline = in_array($attachment->kind, ['image', 'video'], true) || $file->mime_type === 'application/pdf';

        $response = response()->file($disk->path($file->storage_key), [
            'Content-Type' => $file->mime_type,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setContentDisposition($inline ? 'inline' : 'attachment', $attachment->name, Str::ascii($attachment->name));

        return $response;
    }

    public function updateMessage(Request $request, ChatMessage $message): JsonResponse
    {
        $member = $this->current->get();
        $chat = Chat::query()->findOrFail($message->chat_id);
        abort_unless($message->author_id === $member->id && $message->deleted_at === null && $this->access->canRead($member, $chat), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $message->forceFill(['body' => trim($data['body']), 'edited_at' => Carbon::now()])->save();

        return response()->json(['data' => $this->presentMessages($chat, collect([$message]), $member)[0]]);
    }

    /** Own messages; moderators also those of others. The text is removed. */
    public function destroyMessage(ChatMessage $message): JsonResponse
    {
        $member = $this->current->get();
        $chat = Chat::query()->findOrFail($message->chat_id);
        abort_unless(($message->author_id === $member->id && $this->access->canRead($member, $chat)) || $this->access->canModerate($member, $chat), 403);

        $message->forceFill(['body' => '', 'deleted_at' => Carbon::now()])->save();
        ChatAttachment::query()->with('file')->where('message_id', $message->id)->get()->each(fn (ChatAttachment $a) => app(AttachmentStore::class)->delete($a));

        return response()->json(['data' => $this->presentMessages($chat, collect([$message]), $member)[0]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Chat $chat, TenantMember $member): array
    {
        return [
            'id' => $chat->id,
            'kind' => $chat->kind,
            'name' => $chat->displayName(),
            'team_id' => $chat->team_id,
            'mode' => $chat->mode,
            'can_moderate' => $this->access->canModerate($member, $chat),
        ];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function memberList(Chat $chat): array
    {
        $readers = $this->access->readers($chat);
        $names = $this->chats->authorNames($chat, $readers->pluck('id'));

        return $readers->map(fn (TenantMember $m) => ['id' => $m->id, 'name' => $names[$m->id] ?? '–'])
            ->sortBy(fn ($row) => mb_strtolower($row['name']))->values()->all();
    }

    /**
     * @param  Collection<int, ChatMessage>  $messages
     * @return list<array<string, mixed>>
     */
    private function presentMessages(Chat $chat, Collection $messages, TenantMember $viewer): array
    {
        $replies = ChatMessage::query()->whereIn('id', $messages->pluck('reply_to_id')->filter()->unique())->get()->keyBy('id');
        $names = $this->chats->authorNames($chat, $messages->pluck('author_id')->merge($replies->pluck('author_id')));
        $moderator = $this->access->canModerate($viewer, $chat);
        $attachments = ChatAttachment::query()->with('file')->whereIn('message_id', $messages->pluck('id'))->orderBy('id')->get()->groupBy('message_id');

        return $messages->map(fn (ChatMessage $m) => [
            'id' => $m->id,
            'topic_id' => $m->topic_id,
            'author' => ['id' => $m->author_id, 'name' => $names[$m->author_id] ?? '–'],
            'is_mine' => $m->author_id === $viewer->id,
            'body' => $m->deleted_at ? null : $m->body,
            'deleted' => $m->deleted_at !== null,
            'edited' => $m->edited_at !== null,
            'reply_to' => ($r = $replies->get($m->reply_to_id)) ? [
                'id' => $r->id, 'author' => $names[$r->author_id] ?? '–', 'body' => $r->deleted_at ? null : Str::limit($r->body, 80),
            ] : null,
            'attachments' => $m->deleted_at ? [] : ($attachments[$m->id] ?? collect())->map(fn (ChatAttachment $a) => $this->presentAttachment($a))->values()->all(),
            'can_delete' => $m->deleted_at === null && ($m->author_id === $viewer->id || $moderator),
            'created_at' => $m->created_at->toIso8601ZuluString(),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAttachment(ChatAttachment $a): array
    {
        return [
            'id' => $a->id,
            'kind' => $a->kind,
            'name' => $a->name,
            'mime_type' => $a->file?->mime_type,
            'size' => $a->file?->size_bytes,
            'width' => $a->file?->width,
            'height' => $a->file?->height,
            'url' => '/api/v1/t/'.$this->current->get()->tenant->slug.'/chat-attachments/'.$a->id,
        ];
    }

    /**
     * @param  Collection<int, string>  $memberIds
     */
    private function syncMembers(Chat $chat, Collection $memberIds, ?string $moderatorId): void
    {
        $memberIds = $memberIds->unique()->values();
        ChatMember::query()->where('chat_id', $chat->id)->whereNotIn('tenant_member_id', $memberIds)->delete();
        foreach ($memberIds as $id) {
            ChatMember::query()->firstOrCreate(
                ['chat_id' => $chat->id, 'tenant_member_id' => $id],
                ['role' => $id === $moderatorId ? 'moderator' : 'member'],
            );
        }
    }
}
