<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Чат клиента со студией.
 *
 * Клиент работает только со своей перепиской — идентификатор ниоткуда не
 * принимается, она всегда берётся по токену. Администратор ходит в те же
 * методы, но указывает клиента (см. AdminChatController).
 */
class ChatController extends Controller
{
    public function __construct(
        private ChatService $chat,
    ) {}

    /** Лента переписки клиента. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $conversation = $this->chat->forClient($request->user());
        $messages = $this->chat->messages(
            $conversation,
            isset($data['before']) ? (int) $data['before'] : null,
            isset($data['after']) ? (int) $data['after'] : null,
        );

        return response()->json([
            'messages' => MessageResource::collection($messages, $request->user()),
            'has_more' => $this->chat->hasMoreBefore($conversation, $messages->first()?->id),
            'unread' => $conversation->unreadFromStudio()->count(),
            'read_through' => $this->chat->readThrough($conversation, $request->user()),
        ]);
    }

    /** Отправка сообщения студии. */
    public function store(Request $request): JsonResponse
    {
        $this->validateMessage($request);

        $conversation = $this->chat->forClient($request->user());

        $message = $this->chat->send(
            $conversation,
            $request->user(),
            $request->input('body'),
            $request->file('photo'),
        );

        return response()->json([
            'message' => MessageResource::make($message, $request->user()),
        ], 201);
    }

    /** Отметить прочитанным всё, что написала студия. */
    public function read(Request $request): JsonResponse
    {
        $conversation = $this->chat->forClient($request->user());

        return response()->json(['marked' => $this->chat->markRead($conversation, $request->user())]);
    }

    /** Счётчик непрочитанного — для бейджа на вкладке. Работает для обеих ролей. */
    public function unread(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->chat->unreadFor($request->user())]);
    }

    /**
     * Отдача вложения. Прямой ссылки на файл нет: он лежит в приватном диске
     * и проходит через проверку — свою переписку видит клиент, любую — админ.
     */
    public function attachment(Request $request, Message $message): BinaryFileResponse
    {
        $viewer = $request->user();
        $conversation = $message->conversation;

        $allowed = $viewer->role === UserRole::Admin || $conversation->user_id === $viewer->id;

        abort_unless($allowed, 403);
        abort_unless($message->attachmentExists(), 404);

        return response()->file($message->attachmentAbsolutePath(), [
            'Cache-Control' => 'private, max-age=86400',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Общая проверка для клиента и админа: либо текст, либо фото.
     *
     * @throws ValidationException
     */
    public static function validateMessageRequest(Request $request): void
    {
        // Приложение приводит снимок к JPEG перед отправкой, но принимаем и
        // остальные распространённые форматы: с веб-админки файл приходит
        // как есть, да и старая версия приложения может быть у кого-то на руках.
        $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'photo' => ['nullable', 'file', 'mimes:jpeg,jpg,png,webp,gif,heic,heif', 'max:12288'],
        ], [
            'photo.mimes' => 'Такой формат не поддерживается. Подойдут JPEG, PNG, WEBP или HEIC.',
            'photo.max' => 'Фотография слишком большая — до 12 МБ.',
            'body.max' => 'Сообщение слишком длинное — до 4000 символов.',
        ], [
            'body' => 'сообщение',
            'photo' => 'фотография',
        ]);

        if (trim((string) $request->input('body')) === '' && ! $request->hasFile('photo')) {
            throw ValidationException::withMessages([
                'body' => 'Введите сообщение или прикрепите фотографию.',
            ]);
        }
    }

    private function validateMessage(Request $request): void
    {
        self::validateMessageRequest($request);
    }

    /** Клиент по идентификатору — с проверкой, что это действительно клиент. */
    public static function resolveClient(User $user): User
    {
        abort_unless($user->role === UserRole::Client, 404, 'Это не клиент.');

        return $user;
    }
}
