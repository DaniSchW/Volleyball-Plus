<?php

namespace App\Support\Chat;

use App\Enums\NotificationCategory;
use App\Enums\PersonRelation;
use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ChatReadState;
use App\Models\ChatTopic;
use App\Models\PersonAccount;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\TenantMember;
use App\Support\Notifications\Notifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Messages, topics and read markers. Open chats poll for new messages; the
 * others get a push (not to members who looked at the chat a moment ago).
 */
class ChatService
{
    public const PAGE = 50;

    /** Who looked at the chat this recently gets no push for new messages. */
    public const SEEN_SECONDS = 60;

    public function __construct(private ChatAccess $access, private Notifier $notifier) {}

    /** Team chats exist from the first look at the list. */
    public function ensureTeamChats(Collection $teamIds): void
    {
        foreach (Team::query()->whereIn('id', $teamIds)->whereNull('archived_at')->get() as $team) {
            Chat::query()->firstOrCreate(['kind' => Chat::KIND_TEAM, 'team_id' => $team->id]);
        }
    }

    /**
     * @param  list<string>  $attachmentIds  own, not yet sent uploads of this chat
     */
    public function send(Chat $chat, TenantMember $author, string $body, ?string $topicId, ?string $replyToId, array $attachmentIds = []): ChatMessage
    {
        $topicId = $this->topicFor($chat, $topicId);
        if ($replyToId !== null && ! ChatMessage::query()->where('chat_id', $chat->id)->where('id', $replyToId)->exists()) {
            $replyToId = null;
        }
        $attachments = ChatAttachment::query()->whereIn('id', $attachmentIds)
            ->where('chat_id', $chat->id)->where('uploaded_by', $author->id)->whereNull('message_id')->get();
        if ($body === '' && $attachments->isEmpty()) {
            throw ValidationException::withMessages(['body' => __('chat.empty_message')]);
        }

        $message = DB::transaction(function () use ($chat, $topicId, $author, $body, $replyToId, $attachments) {
            $message = ChatMessage::create([
                'chat_id' => $chat->id, 'topic_id' => $topicId, 'author_id' => $author->id,
                'body' => $body, 'reply_to_id' => $replyToId,
            ]);
            ChatAttachment::query()->whereIn('id', $attachments->pluck('id'))->update(['message_id' => $message->id]);

            return $message;
        });
        $this->markRead($chat, $author, $topicId, $message->id);
        $this->announce($chat, $message, $author);

        return $message;
    }

    /**
     * Messages in order. Without cursor the newest page; "before" pages back.
     *
     * @return Collection<int, ChatMessage>
     */
    public function messages(Chat $chat, ?string $topicId, ?string $before = null, ?string $after = null): Collection
    {
        $query = ChatMessage::query()->where('chat_id', $chat->id)
            ->when($chat->hasTopics(), fn ($q) => $q->where('topic_id', $topicId));

        if ($after !== null) {
            return $query->where('id', '>', $after)->orderBy('id')->limit(200)->get();
        }
        if ($before !== null) {
            $query->where('id', '<', $before);
        }

        return $query->orderByDesc('id')->limit(self::PAGE)->get()->reverse()->values();
    }

    /**
     * Messages edited or deleted since a moment (for open chats).
     *
     * @return Collection<int, ChatMessage>
     */
    public function changedSince(Chat $chat, ?string $topicId, Carbon $since, string $upTo): Collection
    {
        return ChatMessage::query()->where('chat_id', $chat->id)
            ->when($chat->hasTopics(), fn ($q) => $q->where('topic_id', $topicId))
            ->where('updated_at', '>', $since)->where('id', '<=', $upTo)
            ->whereColumn('updated_at', '>', 'created_at')
            ->limit(100)->get();
    }

    public function markRead(Chat $chat, TenantMember $member, ?string $topicId, ?string $lastId): void
    {
        $state = ChatReadState::query()->firstOrNew([
            'chat_id' => $chat->id, 'tenant_member_id' => $member->id, 'topic_key' => $chat->hasTopics() ? (string) $topicId : '',
        ]);
        if ($lastId !== null && ($state->last_read_message_id === null || strcmp($lastId, $state->last_read_message_id) > 0)) {
            $state->last_read_message_id = $lastId;
        }
        $state->last_seen_at = Carbon::now();
        $state->save();
    }

    /**
     * Unread messages per chat (and per topic) of others.
     *
     * @param  Collection<int, Chat>  $chats
     * @return array<string, array{total: int, topics: array<string, int>}>
     */
    public function unread(Collection $chats, TenantMember $member): array
    {
        $states = ChatReadState::query()->whereIn('chat_id', $chats->pluck('id'))->where('tenant_member_id', $member->id)->get()
            ->groupBy('chat_id');
        $result = [];

        foreach ($chats as $chat) {
            $marks = ($states[$chat->id] ?? collect())->keyBy('topic_key');
            $counts = ChatMessage::query()->where('chat_id', $chat->id)->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('author_id')->orWhere('author_id', '!=', $member->id))
                ->when(! $chat->hasTopics(), function ($q) use ($marks) {
                    $last = $marks->get('')?->last_read_message_id;
                    $last !== null && $q->where('id', '>', $last);
                })
                ->selectRaw('topic_id, id')->toBase()->get();

            if ($chat->hasTopics()) {
                $topics = $counts->groupBy('topic_id')->map(fn ($rows, $topic) => $rows->filter(
                    fn ($row) => ($last = $marks->get((string) $topic)?->last_read_message_id) === null || strcmp($row->id, $last) > 0,
                )->count())->filter()->all();
                $result[$chat->id] = ['total' => array_sum($topics), 'topics' => $topics];
            } else {
                $result[$chat->id] = ['total' => $counts->count(), 'topics' => []];
            }
        }

        return $result;
    }

    /** Switching to topics puts the messages so far into "Allgemein". */
    public function setMode(Chat $chat, string $mode): void
    {
        DB::transaction(function () use ($chat, $mode) {
            if ($mode === Chat::MODE_TOPICS && ! $chat->hasTopics()) {
                $general = $chat->topics()->first() ?? ChatTopic::create(['chat_id' => $chat->id, 'name' => __('chat.general'), 'sort_order' => 0]);
                ChatMessage::query()->where('chat_id', $chat->id)->whereNull('topic_id')->update(['topic_id' => $general->id]);
            }
            $chat->forceFill(['mode' => $mode])->save();
        });
    }

    /**
     * Display name of an author: own name; parents writing in a team chat
     * also get the child's name ("Eva Eltern · Kim").
     *
     * @param  Collection<int, string|null>  $authorIds
     * @return array<string, string>
     */
    public function authorNames(Chat $chat, Collection $authorIds): array
    {
        $links = PersonAccount::query()->whereIn('tenant_member_id', $authorIds->filter()->unique())->whereNull('ended_at')->with('person')->get()
            ->groupBy('tenant_member_id');
        $teamPersons = $chat->kind === Chat::KIND_TEAM
            ? TeamMembership::query()->where('team_id', $chat->team_id)->pluck('person_id')->flip()
            : collect();

        return $links->map(function (Collection $own) use ($teamPersons) {
            $self = $own->firstWhere('relation', PersonRelation::Own)?->person;
            $children = $own->where('relation', PersonRelation::Guardian)
                ->filter(fn ($l) => $teamPersons->has($l->person_id))->map(fn ($l) => $l->person?->first_name)->filter();
            $name = $self ? $self->first_name.' '.$self->last_name : ($children->first() ? __('chat.parent_of', ['name' => $children->first()]) : '–');
            $inTeam = $self && $teamPersons->has($self->id);

            return ! $inTeam && $children->isNotEmpty() && $self ? $name.' · '.$children->implode(', ') : $name;
        })->all();
    }

    /** "📷 Foto", "🎥 Video" or "📄 plan.pdf" for messages without text. */
    public function attachmentLabel(ChatMessage $message): string
    {
        $first = ChatAttachment::query()->where('message_id', $message->id)->orderBy('id')->first();

        return $first ? __('chat.attachment.'.$first->kind, ['name' => $first->name]) : '';
    }

    private function topicFor(Chat $chat, ?string $topicId): ?string
    {
        if (! $chat->hasTopics()) {
            return null;
        }
        $topic = $chat->topics()->where('id', $topicId)->first();
        if ($topic === null) {
            throw ValidationException::withMessages(['topic_id' => __('chat.topic_required')]);
        }

        return $topic->id;
    }

    private function announce(Chat $chat, ChatMessage $message, TenantMember $author): void
    {
        $recent = ChatReadState::query()->where('chat_id', $chat->id)->where('last_seen_at', '>=', Carbon::now()->subSeconds(self::SEEN_SECONDS))
            ->pluck('tenant_member_id')->flip();
        $recipients = $this->access->readers($chat)->reject(fn (TenantMember $m) => $recent->has($m->id))->values();
        $name = $this->authorNames($chat, collect([$author->id]))[$author->id] ?? '';
        $topic = $message->topic_id ? ChatTopic::query()->find($message->topic_id)?->name : null;

        $this->notifier->toMembers(NotificationCategory::Chat, $recipients, fn () => [
            'title' => $chat->displayName().($topic ? ' · '.$topic : ''),
            'body' => $name.': '.($message->body !== '' ? Str::limit($message->body, 120) : $this->attachmentLabel($message)),
        ], '/chat/'.$chat->id.($message->topic_id ? '?topic='.$message->topic_id : ''), $author);
    }
}
