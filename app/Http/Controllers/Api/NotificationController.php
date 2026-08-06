<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientNotification;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Лента уведомлений и регистрация устройств для пушей.
 *
 * Пользователь всегда работает только со своими уведомлениями: идентификатор
 * ниоткуда не принимается, всё берётся по токену Sanctum.
 *
 * Роль не проверяется намеренно — сейчас уведомления получают только клиенты,
 * но тренерские («у вас завтра занятие в 9:00») лягут сюда же без правки API.
 */
class NotificationController extends Controller
{
    /** Сколько уведомлений отдаём в ленту: глубже клиент не листает. */
    private const LIMIT = 50;

    /** Лента со счётчиком непрочитанных. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = $user->clientNotifications()
            ->latest('created_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (ClientNotification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'payload' => $n->payload,
                'read' => $n->isRead(),
                'date' => $n->formattedDate(),
            ]);

        return response()->json([
            'data' => $items,
            'unread' => $user->clientNotifications()->unread()->count(),
        ]);
    }

    /**
     * Только счётчик — для бейджа.
     * Отдельно от index, потому что бейдж опрашивается регулярно, а тянуть
     * ради числа полсотни записей с текстом незачем.
     */
    public function unread(Request $request): JsonResponse
    {
        return response()->json([
            'unread' => $request->user()->clientNotifications()->unread()->count(),
        ]);
    }

    /** Отметить прочитанным: одно уведомление или сразу все. */
    public function read(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $request->user()->clientNotifications()->unread();

        if (isset($data['id'])) {
            $query->whereKey($data['id']);
        }

        $query->update(['read_at' => now()]);

        return response()->json([
            'unread' => $request->user()->clientNotifications()->unread()->count(),
        ]);
    }

    /**
     * Регистрация устройства.
     *
     * Токен уникален глобально: одно устройство — одна строка. Если на телефоне
     * сменился аккаунт, строка переезжает к новому владельцу, иначе пуши
     * продолжали бы уходить предыдущему.
     */
    public function storeToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'in:expo,fcm,apns'],
            'platform' => ['nullable', 'string', 'in:ios,android,web'],
        ]);

        PushToken::query()->updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'provider' => $data['provider'] ?? 'expo',
                'platform' => $data['platform'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return response()->json(['message' => 'Устройство зарегистрировано.']);
    }

    /** Отвязка устройства — вызывается при выходе из аккаунта. */
    public function destroyToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        // Только своё устройство: чужой токен удалить нельзя.
        $request->user()->pushTokens()->where('token', $data['token'])->delete();

        return response()->json(['message' => 'Устройство отвязано.']);
    }
}
