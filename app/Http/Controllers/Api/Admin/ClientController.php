<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ClientAccessService;
use App\Services\SubscriptionBalanceService;
use App\Support\PhoneNormalizer;
use App\Support\RussianDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Клиенты студии: поиск, создание, карточка и правка.
 *
 * Карточка — то место, где в вебе всё сшивается через пользователя: абонементы,
 * записи, оплаты и ограничения по здоровью.
 */
class ClientController extends Controller
{
    /** Поиск клиентов. */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $clients = User::query()
            ->where('role', UserRole::Client)
            ->when($q !== '', function ($query) use ($q) {
                $phone = PhoneNormalizer::normalize($q);
                $query->where(function ($sub) use ($q, $phone) {
                    $sub->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                    if ($phone) {
                        $sub->orWhere('phone', 'like', "%{$phone}%");
                    }
                });
            })
            ->orderBy('last_name')
            ->limit(50)
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->fullName(),
                // Инициалы и фотография — список лиц читается быстрее списка строк,
                // так же устроен список переписок.
                'initials' => $u->initials(),
                'avatar' => $u->avatarUrl(),
                'phone' => $u->formattedPhone() ?? $u->phone,
                'email' => $u->email,
                'active_subscriptions' => $u->subscriptions()->active()->count(),
            ]);

        return response()->json(['data' => $clients]);
    }

    /** Создать клиента и выслать доступ. */
    public function store(Request $request, ClientAccessService $access): JsonResponse
    {
        $request->merge(['phone' => PhoneNormalizer::normalize($request->input('phone'))]);
        if ($request->filled('email')) {
            $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'patronymic' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'size:11', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'birth_day' => ['nullable', 'integer', 'between:1,31'],
            'birth_month' => ['nullable', 'integer', 'between:1,12'],
        ], ['phone.size' => 'Введите корректный номер телефона.', 'phone.unique' => 'Этот телефон уже зарегистрирован.']);

        $user = User::query()->create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'patronymic' => $data['patronymic'] ?? null,
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'birth_day' => $data['birth_day'] ?? null,
            'birth_month' => $data['birth_month'] ?? null,
            'role' => UserRole::Client,
            'password' => Str::password(12),
        ]);

        $accessResult = null;
        if (filled($user->email)) {
            try {
                $accessResult = $access->sendTemporaryPassword($user);
            } catch (\Throwable $e) {
                $accessResult = ['error' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => 'Клиент создан'.($accessResult && ($accessResult['email'] ?? false) ? ', доступ выслан на email.' : '.'),
            'id' => $user->id,
        ]);
    }

    /** Карточка клиента: данные, абонементы, записи, оплаты. */
    public function show(User $client, SubscriptionBalanceService $balances): JsonResponse
    {
        $this->assertClient($client);

        $subscriptions = $client->subscriptions()->orderByDesc('ends_at')->get();
        $breakdowns = $balances->breakdownForMany($subscriptions);

        $bookings = $client->bookings()
            ->with('classSession.direction')
            ->get()
            ->sortByDesc(fn (Booking $b) => $b->classSession?->starts_at)
            ->values();

        [$upcoming, $past] = $bookings->partition(
            fn (Booking $b) => $b->isConfirmed() && $b->classSession?->starts_at?->isFuture(),
        );

        return response()->json([
            'client' => $this->card($client),
            'stats' => [
                'visits' => $client->bookings()->where('attendance_status', AttendanceStatus::Attended)->count(),
                'active_subscriptions' => $subscriptions->filter(fn (Subscription $s) => $s->isActive())->count(),
                'upcoming' => $upcoming->count(),
            ],
            'subscriptions' => $subscriptions->map(fn (Subscription $s) => [
                'id' => $s->id,
                'type' => $s->type->value,
                'type_label' => $s->type->label(),
                'type_short' => $s->type->shortLabel(),
                'sessions_total' => $s->sessions_total,
                'sessions_used' => $s->sessions_used,
                'sessions_remaining' => $breakdowns[$s->id]['sessions_remaining'],
                'sessions_reserved' => $breakdowns[$s->id]['sessions_reserved'],
                'sessions_consumed' => $breakdowns[$s->id]['sessions_consumed'],
                'sessions_per_day' => $s->sessionsPerDay(),
                'purchased_at' => $s->purchased_at?->toDateString(),
                'starts_at' => $s->starts_at->toDateString(),
                'ends_at' => $s->ends_at->toDateString(),
                'is_active' => $s->isActive(),
                'admin_note' => $s->admin_note,
            ])->values(),
            // Предстоящие — по возрастанию: администратор смотрит, что ближе.
            'upcoming' => $upcoming->sortBy(fn (Booking $b) => $b->classSession?->starts_at)
                ->values()
                ->map(fn (Booking $b) => $this->booking($b)),
            'past' => $past->take(30)->map(fn (Booking $b) => $this->booking($b))->values(),
            'payments' => $client->payments()->latest()->limit(20)->get()->map(fn (Payment $p) => [
                'id' => $p->id,
                'amount' => $p->amount,
                'status' => $p->status->value,
                'description' => $p->description,
                'date' => RussianDate::dayMonthYear($p->created_at),
            ])->values(),
        ]);
    }

    /** Правка данных клиента. Роль здесь не меняется — это не смена ролей. */
    public function update(Request $request, User $client): JsonResponse
    {
        $this->assertClient($client);

        $request->merge(['phone' => PhoneNormalizer::normalize($request->input('phone'))]);
        if ($request->filled('email')) {
            $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'patronymic' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'size:11', 'unique:users,phone,'.$client->id],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,'.$client->id],
            'birth_day' => ['nullable', 'integer', 'between:1,31'],
            'birth_month' => ['nullable', 'integer', 'between:1,12'],
            'birth_year' => ['nullable', 'integer', 'between:1920,'.now()->year],
            'health_note' => ['nullable', 'string', 'max:5000'],
            'health_note_visible_to_trainer' => ['boolean'],
        ], [
            'phone.size' => 'Введите корректный номер телефона.',
            'phone.unique' => 'Этот телефон уже зарегистрирован.',
            'email.unique' => 'Этот email уже занят.',
        ]);

        $client->update([
            ...$data,
            'health_note' => filled($data['health_note'] ?? null) ? $data['health_note'] : null,
            'email' => $data['email'] ?? null,
        ]);

        return response()->json([
            'message' => 'Данные клиента сохранены.',
            'client' => $this->card($client->refresh()),
        ]);
    }

    /**
     * Выслать новый временный пароль — кнопка «Отправить доступ» из админки.
     * Частая просьба в чате: «не могу войти».
     */
    public function access(User $client, ClientAccessService $access): JsonResponse
    {
        $this->assertClient($client);

        try {
            $result = $access->sendTemporaryPassword($client);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $channels = array_filter([
            ($result['email'] ?? false) ? 'email' : null,
            ($result['telegram'] ?? false) ? 'Telegram' : null,
        ]);

        if ($channels === []) {
            // Пароль уже сменён, но не доставлен — его надо назвать вслух,
            // иначе клиент останется без входа.
            return response()->json([
                'message' => 'Пароль обновлён, но отправить не удалось. Новый пароль: '.$result['password'],
            ], 422);
        }

        return response()->json([
            'message' => 'Отправлено ('.implode(' и ', $channels).'). Пароль: '.$result['password'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(User $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->fullName(),
            'initials' => $client->initials(),
            'avatar_url' => $client->avatarUrl(),
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'patronymic' => $client->patronymic,
            'phone' => $client->phone,
            'phone_formatted' => $client->formattedPhone(),
            'email' => $client->email,
            'birth_day' => $client->birth_day,
            'birth_month' => $client->birth_month,
            'birth_year' => $client->birth_year,
            'health_note' => $client->health_note,
            'health_note_visible_to_trainer' => (bool) $client->health_note_visible_to_trainer,
            'offer_accepted' => $client->hasAcceptedOffer(),
            'has_telegram' => $client->hasTelegram(),
            'registered_at' => RussianDate::dayMonthYear($client->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function booking(Booking $booking): array
    {
        $session = $booking->classSession;
        $attendance = $booking->attendance_status ?? AttendanceStatus::Expected;

        return [
            'id' => $booking->id,
            'session_id' => $booking->class_session_id,
            'title' => $session?->title ?? '—',
            'date_time' => $session ? $session->formattedDateTime() : '—',
            'type_label' => $session?->type->shortLabel(),
            'status' => $booking->status->value,
            'status_label' => $booking->status->label(),
            'cancellation_reason' => $booking->cancellation_reason,
            'attendance' => $attendance->value,
            'attendance_label' => $attendance->label(),
            'confirmed' => $booking->status === BookingStatus::Confirmed,
        ];
    }

    /** Карточка есть только у клиента: у тренера и админа ни абонементов, ни записей. */
    private function assertClient(User $client): void
    {
        if ($client->role !== UserRole::Client) {
            throw ValidationException::withMessages([
                'client' => ['Это не клиент студии.'],
            ]);
        }
    }
}
