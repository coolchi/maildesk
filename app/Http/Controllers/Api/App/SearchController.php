<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use App\Services\Chat\ConversationService;
use App\Services\WorkspaceAccess;
use App\Support\EmailSnippet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        public ConversationService $chat,
        public WorkspaceAccess $access,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organization = $this->organization($request);
        $query = trim($request->string('q')->toString());
        $recent = $query === '';

        return response()->json([
            'query' => $query,
            'people' => $this->people($organization, $user, $query, $recent),
            'chats' => $this->chats($organization, $user, $query, $recent),
            'mail' => $this->mail($organization, $user, $query, $recent),
        ]);
    }

    /**
     * @return list<array{id: int, name: string, email: string|null, kind: string}>
     */
    private function people(Organization $organization, User $user, string $query, bool $recent): array
    {
        $limit = $recent ? 8 : 12;
        $like = '%'.strtolower($query).'%';

        $members = $organization->users()
            ->where('users.id', '!=', $user->id)
            ->when(! $recent, function ($builder) use ($like) {
                $builder->where(function ($inner) use ($like) {
                    $inner->whereRaw('lower(users.name) like ?', [$like])
                        ->orWhereRaw('lower(users.email) like ?', [$like]);
                });
            })
            ->get(['users.id', 'users.name', 'users.email']);

        $rankedMemberIds = $this->recentPeopleIds($organization, $user);
        $members = $members
            ->sortBy(function (User $member) use ($rankedMemberIds) {
                $rank = array_search($member->id, $rankedMemberIds, true);

                return $rank === false ? 1000 + $member->id : $rank;
            })
            ->values()
            ->take($limit);

        $people = $members->map(fn (User $member) => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'kind' => 'member',
        ])->all();

        if ($recent) {
            return $people;
        }

        $contacts = $organization->contacts()
            ->where(function ($inner) use ($like) {
                $inner->whereRaw('lower(email) like ?', [$like])
                    ->orWhereRaw('lower(first_name) like ?', [$like])
                    ->orWhereRaw('lower(last_name) like ?', [$like]);
            })
            ->orderBy('email')
            ->limit($limit)
            ->get()
            ->map(function (Contact $contact) {
                $name = trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));

                return [
                    'id' => $contact->id,
                    'name' => $name !== '' ? $name : $contact->email,
                    'email' => $contact->email,
                    'kind' => 'contact',
                ];
            })
            ->all();

        return array_values(array_slice([...$people, ...$contacts], 0, $limit));
    }

    /**
     * @return list<int>
     */
    private function recentPeopleIds(Organization $organization, User $user): array
    {
        $conversationIds = Conversation::query()
            ->where('organization_id', $organization->id)
            ->whereHas('participants', fn ($query) => $query->where('user_id', $user->id))
            ->latest('last_message_at')
            ->limit(40)
            ->pluck('id');

        if ($conversationIds->isEmpty()) {
            return [];
        }

        $ids = [];
        $rows = Conversation::query()
            ->whereIn('id', $conversationIds)
            ->with(['participants' => fn ($query) => $query->where('user_id', '!=', $user->id)])
            ->latest('last_message_at')
            ->get();

        foreach ($rows as $conversation) {
            foreach ($conversation->participants as $participant) {
                if (! in_array($participant->user_id, $ids, true)) {
                    $ids[] = $participant->user_id;
                }
            }
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function chats(Organization $organization, User $user, string $query, bool $recent): array
    {
        $limit = $recent ? 8 : 12;
        $conversations = $this->chat->listFor($organization, $user);

        if (! $recent) {
            $like = strtolower($query);
            $matchingIds = ChatMessage::query()
                ->whereIn('conversation_id', $conversations->pluck('id'))
                ->whereRaw('lower(body) like ?', ['%'.$like.'%'])
                ->limit(40)
                ->pluck('conversation_id')
                ->unique()
                ->all();

            $conversations = $conversations->filter(function (Conversation $conversation) use ($user, $like, $matchingIds) {
                if (in_array($conversation->id, $matchingIds, true)) {
                    return true;
                }

                $haystack = strtolower(implode(' ', array_filter([
                    $conversation->name,
                    $conversation->last_message_preview,
                    ...$conversation->participants
                        ->where('user_id', '!=', $user->id)
                        ->map(fn ($participant) => $participant->user?->name)
                        ->all(),
                ])));

                return str_contains($haystack, $like);
            })->values();
        }

        return $conversations
            ->take($limit)
            ->map(fn (Conversation $conversation) => $conversation->toAppArray($user))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mail(Organization $organization, User $user, string $query, bool $recent): array
    {
        $limit = $recent ? 8 : 12;

        $threads = $this->access->scopeMailData($organization->threads(), $user, $organization)
            ->where('is_trashed', false)
            ->where('is_spam', false)
            ->when(! $recent, function ($builder) use ($query) {
                $like = '%'.strtolower($query).'%';
                $builder->where(function ($inner) use ($like) {
                    $inner->whereRaw('lower(subject) like ?', [$like])
                        ->orWhereRaw('lower(snippet) like ?', [$like])
                        ->orWhereHas('messages', function ($messages) use ($like) {
                            $messages->whereRaw('lower(from_email) like ?', [$like])
                                ->orWhereRaw('lower(from_name) like ?', [$like])
                                ->orWhereRaw('lower(text_body) like ?', [$like]);
                        });
                });
            })
            ->with(['messages' => fn ($builder) => $builder->select('id', 'thread_id', 'from_email', 'from_name', 'headers', 'created_at')->latest()])
            ->latest('last_message_at')
            ->limit($limit)
            ->get();

        return $threads->map(fn (Thread $thread) => $this->mailCard($thread))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mailCard(Thread $thread): array
    {
        $latest = $thread->relationLoaded('messages') ? $thread->messages->first() : null;

        return [
            'id' => $thread->id,
            'subject' => $thread->subject,
            'snippet' => EmailSnippet::display($thread->snippet),
            'from_name' => $latest?->senderName(),
            'from_email' => $latest?->from_email,
            'unread' => ! $thread->is_read,
            'updated' => $thread->last_message_at?->diffForHumans() ?? '',
            'labels' => [],
        ];
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        return $organization;
    }
}
