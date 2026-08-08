<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Запись клиента администратором — то же, что «Записи → Создать» в админке.
 */
class BookingController extends Controller
{
    /**
     * Что нужно знать до записи: ограничения по здоровью и с какого абонемента
     * можно списать. Пустой список абонементов — записать не получится:
     * `bookForAdmin()` без подходящего абонемента бросает исключение.
     */
    public function options(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'class_session_id' => ['required', 'integer', 'exists:class_sessions,id'],
        ]);

        $user = $this->client((int) $data['user_id']);
        $session = ClassSession::query()->findOrFail($data['class_session_id']);

        $usable = $subscriptions->usableForUserOnDate($user, $session->type, $session->starts_at);

        return response()->json([
            'client' => [
                'id' => $user->id,
                'name' => $user->fullName(),
                'phone' => $user->formattedPhone() ?? $user->phone,
                'health_note' => filled($user->health_note) ? $user->health_note : null,
            ],
            'session' => [
                'id' => $session->id,
                'title' => $session->title,
                'date_time' => $session->formattedDateTime(),
                'type_label' => $session->type->shortLabel(),
                'taken' => $session->confirmedCount(),
                'capacity' => $session->capacity,
            ],
            'subscriptions' => collect($usable)->map(fn (Subscription $s) => [
                'id' => $s->id,
                'label' => $s->type->shortLabel(),
                'remaining' => $s->sessionsRemaining(),
                'total' => $s->sessions_total,
                'starts_at' => $s->starts_at->format('d.m.Y'),
                'ends_at' => $s->ends_at->format('d.m.Y'),
            ])->values(),
            // Формулировка для администратора: клиентское «купите тариф в личном
            // кабинете» из SubscriptionService тут не к месту.
            'note' => $usable === []
                ? 'Действующего абонемента нужного типа нет — сначала выдайте абонемент.'
                : null,
        ]);
    }

    /** Записать клиента. Абонемент не указан — подберётся сам. */
    public function store(Request $request, BookingService $bookings): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'class_session_id' => ['required', 'integer', 'exists:class_sessions,id'],
            'subscription_id' => ['nullable', 'integer', 'exists:subscriptions,id'],
        ]);

        $user = $this->client((int) $data['user_id']);
        $session = ClassSession::query()->findOrFail($data['class_session_id']);
        $subscription = filled($data['subscription_id'] ?? null)
            ? Subscription::query()->findOrFail($data['subscription_id'])
            : null;

        try {
            $booking = $bookings->bookForAdmin($user, $session, $subscription);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $user->shortName().' записан(а) на «'.$session->title.'».',
            'id' => $booking->id,
        ]);
    }

    /** Записывать можно только клиента: тренер или админ в ростере — ошибка данных. */
    private function client(int $id): User
    {
        $user = User::query()->findOrFail($id);

        if ($user->role !== UserRole::Client) {
            throw ValidationException::withMessages([
                'user_id' => ['Записать можно только клиента.'],
            ]);
        }

        return $user;
    }
}
