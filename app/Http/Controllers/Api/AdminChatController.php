<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ChatService;
use App\Support\RussianDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Переписки со стороны студии: список клиентов и лента конкретного клиента.
 */
class AdminChatController extends Controller
{
    public function __construct(
        private ChatService $chat,
    ) {}

    /** Список переписок: кто, что написал последним, сколько непрочитанного. */
    public function index(Request $request): JsonResponse
    {
        $conversations = $this->chat->conversationsForAdmin($request->query('q'));

        return response()->json([
            'data' => $conversations->map(fn (Conversation $c) => [
                'user_id' => $c->user_id,
                'name' => $c->user->fullName(),
                'phone' => $c->user->formattedPhone() ?? $c->user->phone,
                'initials' => $c->user->initials(),
                'last_message' => $c->latestMessage?->preview(),
                'last_from_client' => $c->latestMessage !== null
                    ? $c->latestMessage->sender_id === $c->user_id
                    : null,
                'last_at' => $c->last_message_at?->toIso8601String(),
                'last_label' => $c->last_message_at ? $this->shortWhen($c->last_message_at) : null,
                'unread' => (int) ($c->unread_count ?? 0),
            ])->values(),
            'unread_total' => $this->chat->unreadFor($request->user()),
        ]);
    }

    /** Лента переписки с конкретным клиентом. */
    public function show(Request $request, User $client): JsonResponse
    {
        ChatController::resolveClient($client);

        $data = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $conversation = $this->chat->forClient($client);
        $messages = $this->chat->messages(
            $conversation,
            isset($data['before']) ? (int) $data['before'] : null,
            isset($data['after']) ? (int) $data['after'] : null,
        );

        return response()->json([
            'client' => [
                'id' => $client->id,
                'name' => $client->fullName(),
                'phone' => $client->formattedPhone() ?? $client->phone,
                'initials' => $client->initials(),
            ],
            'messages' => MessageResource::collection($messages, $request->user()),
            'has_more' => $this->chat->hasMoreBefore($conversation, $messages->first()?->id),
            'read_through' => $this->chat->readThrough($conversation, $request->user()),
        ]);
    }

    /** Ответ клиенту. */
    public function store(Request $request, User $client): JsonResponse
    {
        ChatController::resolveClient($client);
        ChatController::validateMessageRequest($request);

        $message = $this->chat->send(
            $this->chat->forClient($client),
            $request->user(),
            $request->input('body'),
            $request->file('photo'),
        );

        return response()->json([
            'message' => MessageResource::make($message, $request->user()),
        ], 201);
    }

    /** Отметить прочитанным всё, что написал клиент. */
    public function read(Request $request, User $client): JsonResponse
    {
        ChatController::resolveClient($client);

        return response()->json([
            'marked' => $this->chat->markRead($this->chat->forClient($client), $request->user()),
        ]);
    }

    /** «14:20» для сегодняшнего, «вчера», иначе «5 августа». */
    private function shortWhen(Carbon $at): string
    {
        if ($at->isToday()) {
            return $at->format('H:i');
        }

        if ($at->isYesterday()) {
            return 'вчера';
        }

        return RussianDate::dayMonth($at);
    }
}
