<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SubscriptionService;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Запись клиента администратором — то же, что «Записи → Создать» в админке.
 */
class BookingController extends Controller
{
    /**
     * Реестр записей: период, тип занятия, статус, поиск по клиенту.
     *
     * Выбран период — читаем вперёд от его начала; период не задан — сверху
     * самые свежие, как в журнале.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'type' => ['nullable', 'in:group,individual,special_event'],
            'status' => ['nullable', 'in:confirmed,cancelled'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $q = trim((string) ($data['q'] ?? ''));
        $ascending = filled($data['date_from'] ?? null) || filled($data['date_to'] ?? null);

        $bookings = Booking::query()
            ->with(['user', 'classSession'])
            ->whereHas('classSession', function ($query) use ($data) {
                if (filled($data['date_from'] ?? null)) {
                    $query->where('starts_at', '>=', Carbon::parse($data['date_from'])->startOfDay());
                }
                if (filled($data['date_to'] ?? null)) {
                    $query->where('starts_at', '<=', Carbon::parse($data['date_to'])->endOfDay());
                }
                if (filled($data['type'] ?? null)) {
                    $query->where('type', $data['type']);
                }
            })
            // Столбец обязательно с именем таблицы: ниже join с class_sessions,
            // а `status` есть в обеих — иначе SQLite падает на неоднозначности.
            ->when(($data['status'] ?? null) === 'confirmed', fn ($query) => $query->where('bookings.status', BookingStatus::Confirmed))
            ->when(($data['status'] ?? null) === 'cancelled', fn ($query) => $query->where('bookings.status', '!=', BookingStatus::Confirmed))
            ->when($q !== '', function ($query) use ($q) {
                $phone = PhoneNormalizer::normalize($q);
                $query->whereHas('user', function ($sub) use ($q, $phone) {
                    $sub->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                    if ($phone) {
                        $sub->orWhere('phone', 'like', "%{$phone}%");
                    }
                });
            })
            // Сортировка по времени занятия, а не по дате создания записи:
            // администратор ищет запись по занятию.
            ->join('class_sessions', 'class_sessions.id', '=', 'bookings.class_session_id')
            ->orderBy('class_sessions.starts_at', $ascending ? 'asc' : 'desc')
            ->select('bookings.*')
            ->paginate(perPage: 30, page: $data['page'] ?? 1);

        return response()->json([
            'data' => collect($bookings->items())->map(function (Booking $b) {
                $attendance = $b->attendance_status ?? AttendanceStatus::Expected;

                return [
                    'id' => $b->id,
                    'client' => $b->user?->fullName() ?? '—',
                    'client_id' => $b->user_id,
                    'session_id' => $b->class_session_id,
                    'title' => $b->classSession?->title ?? '—',
                    'date_time' => $b->classSession ? $b->classSession->formattedDateTime() : '—',
                    'type_label' => $b->classSession?->type->shortLabel(),
                    'status' => $b->status->value,
                    'status_label' => $b->status->label(),
                    'attendance' => $attendance->value,
                    'attendance_label' => $attendance->label(),
                    'confirmed' => $b->status === BookingStatus::Confirmed,
                ];
            })->values(),
            'meta' => PaymentController::meta($bookings),
        ]);
    }

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
