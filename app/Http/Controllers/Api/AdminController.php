<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\Direction;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ClientAccessService;
use App\Support\PhoneNormalizer;
use App\Support\RussianDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class AdminController extends Controller
{
    /** Сводка. */
    public function overview(): JsonResponse
    {
        $today = now()->startOfDay();

        return response()->json([
            'clients' => User::query()->where('role', UserRole::Client)->count(),
            'trainers' => User::query()->where('role', UserRole::Trainer)->count(),
            'sessions_today' => ClassSession::query()->whereDate('starts_at', $today)->count(),
            'active_subscriptions' => Subscription::query()->active()->count(),
            'bookings_upcoming' => \App\Models\Booking::query()
                ->where('status', BookingStatus::Confirmed)
                ->whereHas('classSession', fn ($q) => $q->where('starts_at', '>=', now()))
                ->count(),
        ]);
    }

    /** Справочники для форм (направления, тренеры). */
    public function meta(): JsonResponse
    {
        return response()->json([
            'directions' => Direction::query()->ordered()->get(['id', 'title'])
                ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title]),
            'trainers' => User::query()->where('role', UserRole::Trainer)->orderBy('last_name')->get()
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->shortName()]),
            'types' => collect(SubscriptionType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
        ]);
    }

    /** Занятия в окне дат (по умолчанию — ближайшие 7 дней), включая отменённые. */
    public function sessions(Request $request): JsonResponse
    {
        $offset = max(0, (int) $request->query('offset', 0));
        $start = now()->startOfDay()->addDays($offset * 7);
        $end = $start->copy()->addDays(6)->endOfDay();

        $sessions = ClassSession::query()
            ->whereBetween('starts_at', [$start, $end])
            ->with(['trainer', 'direction'])
            ->withCount(['bookings as taken' => fn ($q) => $q->where('status', BookingStatus::Confirmed)])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ClassSession $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'date_time' => $s->formattedDateTime(),
                'time' => $s->formattedTime(),
                'trainer' => $s->trainerName(),
                'type' => $s->type->badgeClass(),
                'type_label' => $s->type->shortLabel(),
                'taken' => (int) $s->taken,
                'capacity' => $s->capacity,
                'status' => $s->status->value,
                'cancelled' => $s->isCancelled(),
                'reason' => $s->cancellation_reason,
            ]);

        return response()->json([
            'range_label' => RussianDate::dayMonthRange($start, $start->copy()->addDays(6)),
            'offset' => $offset,
            'prev_offset' => max(0, $offset - 1),
            'next_offset' => $offset + 1,
            'can_go_prev' => $offset > 0,
            'sessions' => $sessions,
        ]);
    }

    /** Создать занятие. */
    public function createSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'direction_id' => ['nullable', 'integer', 'exists:directions,id'],
            'topic' => ['nullable', 'string', 'max:120'],
            'date' => ['required', 'date'],
            'time' => ['required', 'string'],
            'type' => ['required', 'in:group,individual,special_event'],
            'capacity' => ['required', 'integer', 'between:1,50'],
            'trainer_id' => ['nullable', 'integer', 'exists:users,id'],
            'duration_minutes' => ['nullable', 'integer', 'between:15,240'],
        ]);

        $startsAt = Carbon::parse($data['date'].' '.$data['time']);

        $session = ClassSession::query()->create([
            'direction_id' => $data['direction_id'] ?? null,
            'topic' => $data['topic'] ?? null,
            'starts_at' => $startsAt,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'type' => $data['type'],
            'capacity' => $data['capacity'],
            'trainer_id' => $data['trainer_id'] ?? null,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        return response()->json(['message' => 'Занятие создано: «'.$session->title.'».', 'id' => $session->id]);
    }

    /** Отменить занятие с причиной. */
    public function cancelSession(Request $request, ClassSession $session, BookingService $bookings): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $bookings->cancelClass($session, $data['reason']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Занятие отменено, клиенты уведомлены.']);
    }

    /** Поиск клиентов. */
    public function clients(Request $request): JsonResponse
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
                'phone' => $u->formattedPhone() ?? $u->phone,
                'email' => $u->email,
                'active_subscriptions' => $u->subscriptions()->active()->count(),
            ]);

        return response()->json(['data' => $clients]);
    }

    /** Создать клиента и выслать доступ. */
    public function createClient(Request $request, ClientAccessService $access): JsonResponse
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
            'password' => \Illuminate\Support\Str::password(12),
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

    /** Выдать абонемент клиенту. */
    public function issueSubscription(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', 'in:group,individual,special_event'],
            'sessions_total' => ['required', 'integer', 'between:1,100'],
            'starts_at' => ['required', 'date'],
            'validity_days' => ['nullable', 'integer', 'between:1,365'],
        ]);

        $startsAt = Carbon::parse($data['starts_at'])->startOfDay();
        $endsAt = $startsAt->copy()->addDays($data['validity_days'] ?? 30);

        Subscription::query()->create([
            'user_id' => $data['user_id'],
            'type' => $data['type'],
            'sessions_total' => $data['sessions_total'],
            'sessions_used' => 0,
            'sessions_per_day' => 1,
            'purchased_at' => now(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'admin_note' => 'Выдан администратором через приложение',
        ]);

        return response()->json(['message' => 'Абонемент выдан.']);
    }

    /** Последние оплаты. */
    public function payments(): JsonResponse
    {
        $payments = Payment::query()
            ->with('user')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'client' => $p->user?->fullName() ?? '—',
                'amount' => $p->amount,
                'status' => $p->status->value,
                'description' => $p->description,
                'date' => RussianDate::dayMonthYear($p->created_at),
            ]);

        return response()->json(['data' => $payments]);
    }
}
