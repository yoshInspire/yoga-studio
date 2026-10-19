<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\User;
use App\Services\BookingService;
use App\Support\RussianDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Расписание со стороны студии.
 */
class SessionController extends Controller
{
    /**
     * Занятия недели, включая отменённые.
     *
     * Окно считается от сегодняшнего дня со сдвигом в неделях; отрицательный
     * сдвиг — прошлые недели: администратору нужно сверять посещения задним
     * числом. Дальше года в обе стороны не пускаем — это уже не расписание.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'offset' => ['nullable', 'integer', 'between:-52,52'],
            'type' => ['nullable', 'in:group,individual,special_event'],
            'status' => ['nullable', 'in:scheduled,cancelled'],
        ]);

        $offset = (int) ($data['offset'] ?? 0);
        $start = now()->startOfDay()->addDays($offset * 7);
        $end = $start->copy()->addDays(6)->endOfDay();

        $sessions = ClassSession::query()
            ->whereBetween('starts_at', [$start, $end])
            ->when($data['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with(['trainer', 'direction', 'reservedClients'])
            ->withCount(['bookings as taken' => fn ($q) => $q->where('status', BookingStatus::Confirmed)])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ClassSession $s) => [
                'id' => $s->id,
                'title' => $s->title,
                // Направление и тема отдельно: в приложении карточка занятия
                // красится в цвет семейства и разводит их по строкам, как в
                // клиентском расписании.
                'direction' => $s->direction?->title,
                'direction_slug' => $s->direction?->slug,
                'topic' => $s->topic,
                'date_time' => $s->formattedDateTime(),
                'time' => $s->formattedTime(),
                'time_range' => $s->formattedTimeRange(),
                'trainer' => $s->trainerName(),
                'type' => $s->type->badgeClass(),
                'type_label' => $s->type->shortLabel(),
                'taken' => (int) $s->taken,
                'capacity' => $s->capacity,
                'status' => $s->status->value,
                'cancelled' => $s->isCancelled(),
                'reason' => $s->cancellation_reason,
                // Под кого открыта запись; пусто — для всех.
                'reserved_clients' => $s->reservedClients
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->fullName()])
                    ->values(),
                // Поля для формы правки: отдельного запроса на одно занятие
                // не делаем, неделя и так приходит целиком.
                'form' => [
                    'direction_id' => $s->direction_id,
                    'topic' => $s->topic,
                    'description' => $s->description,
                    'date' => $s->starts_at->toDateString(),
                    'time' => $s->starts_at->format('H:i'),
                    'type' => $s->type->value,
                    'duration_minutes' => $s->durationMinutes(),
                    'capacity' => $s->capacity,
                    'trainer_id' => $s->trainer_id,
                    'reserved_client_ids' => $s->reservedClients->pluck('id')->values(),
                ],
            ]);

        return response()->json([
            'range_label' => RussianDate::dayMonthRange($start, $start->copy()->addDays(6)),
            'offset' => $offset,
            'prev_offset' => max(-52, $offset - 1),
            'next_offset' => min(52, $offset + 1),
            'can_go_prev' => $offset > -52,
            'can_go_next' => $offset < 52,
            'sessions' => $sessions,
        ]);
    }

    /** Создать занятие. */
    public function store(Request $request, BookingService $bookings): JsonResponse
    {
        $data = $this->validateForm($request);

        $session = DB::transaction(function () use ($data) {
            $session = ClassSession::query()->create([
                ...$this->attributes($data),
                'status' => ClassSessionStatus::Scheduled,
            ]);

            $this->syncReservedClients($session, $data);

            return $session;
        });

        $message = 'Занятие создано: «'.$session->title.'».';
        $message .= $this->bookingSummary($bookings->bookReservedClients($session));

        return response()->json(['message' => $message, 'id' => $session->id]);
    }

    /**
     * Изменить занятие.
     *
     * Отменить занятие этим маршрутом нельзя: отмена — это ещё возврат занятий
     * на абонементы и письма записавшимся, за это отвечает `cancel()`. Обратный
     * перевод в «по расписанию» разрешён — так снимают ошибочную отмену.
     */
    public function update(Request $request, ClassSession $session, BookingService $bookings): JsonResponse
    {
        $data = $this->validateForm($request, [
            'status' => ['nullable', 'in:scheduled'],
        ]);

        $session->fill($this->attributes($data));

        if (($data['status'] ?? null) === 'scheduled' && $session->isCancelled()) {
            $session->status = ClassSessionStatus::Scheduled;
            $session->cancellation_reason = null;
        }

        $added = DB::transaction(function () use ($session, $data) {
            $session->save();

            return $this->syncReservedClients($session, $data);
        });

        $message = 'Занятие обновлено: «'.$session->title.'».';

        // Записываем только добавленных сейчас — как в веб-админке.
        if ($added !== []) {
            $message .= $this->bookingSummary($bookings->bookReservedClients($session, $added));
        }

        return response()->json(['message' => $message]);
    }

    /**
     * Сохранить, под кого открыта запись. Поле не прислали — не трогаем
     * (старая версия приложения о нём не знает). Только для индивидуальных:
     * у остальных привязку снимает сама модель.
     *
     * @param  array<string, mixed>  $data
     * @return list<int> добавленные клиенты
     */
    private function syncReservedClients(ClassSession $session, array $data): array
    {
        if (! array_key_exists('reserved_client_ids', $data) || $session->type !== SubscriptionType::Individual) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $data['reserved_client_ids'] ?? [])));

        if (count($ids) > $session->capacity) {
            throw ValidationException::withMessages([
                'reserved_client_ids' => ['Клиентов больше, чем мест на занятии ('.$session->capacity.').'],
            ]);
        }

        $clients = User::query()
            ->whereKey($ids)
            ->where('role', UserRole::Client)
            ->whereNull('anonymized_at')
            ->pluck('id')
            ->all();

        if (count($clients) !== count($ids)) {
            throw ValidationException::withMessages([
                'reserved_client_ids' => ['Открыть запись можно только под клиента студии.'],
            ]);
        }

        return array_values(array_map('intval', $session->reservedClients()->sync($ids)['attached']));
    }

    /**
     * @param  array{booked: list<string>, pending: array<string, string>}  $result
     */
    private function bookingSummary(array $result): string
    {
        $parts = [];

        if ($result['booked'] !== []) {
            $parts[] = 'Записаны: '.implode(', ', $result['booked']).'.';
        }

        foreach ($result['pending'] as $name => $reason) {
            $parts[] = $name.' пока не записан(а): '.$reason.' Время закреплено за клиентом.';
        }

        return $parts === [] ? '' : ' '.implode(' ', $parts);
    }

    /**
     * Удалить занятие.
     *
     * Пока на занятии есть действующие записи — только отмена: удаление снесло
     * бы их каскадом, не вернув занятия на абонементы и не предупредив клиентов.
     */
    public function destroy(ClassSession $session): JsonResponse
    {
        $confirmed = $session->bookings()->where('status', BookingStatus::Confirmed)->count();

        if ($confirmed > 0) {
            return response()->json([
                'message' => 'На занятии есть записи ('.$confirmed.'). Отмените занятие — клиентам вернутся занятия и уйдут уведомления.',
            ], 422);
        }

        $session->delete();

        return response()->json(['message' => 'Занятие удалено.']);
    }

    /**
     * Поля формы занятия. Требования те же, что на странице «Расписание» в
     * админке: тема обязательна, иначе в расписании появляется строка без
     * названия, когда направление не выбрано.
     *
     * @param  array<string, list<string>>  $extra
     * @return array<string, mixed>
     */
    private function validateForm(Request $request, array $extra = []): array
    {
        return $request->validate([
            'direction_id' => ['nullable', 'integer', 'exists:directions,id'],
            'topic' => ['required', 'string', 'max:'.(int) config('studio.class_title_max_length', 120)],
            'description' => ['nullable', 'string', 'max:2000'],
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
            'type' => ['required', 'in:group,individual,special_event'],
            'capacity' => ['required', 'integer', 'between:1,99'],
            'trainer_id' => ['nullable', 'integer', 'exists:users,id'],
            'duration_minutes' => ['nullable', 'integer', 'between:15,300'],
            'reserved_client_ids' => ['sometimes', 'nullable', 'array', 'max:99'],
            'reserved_client_ids.*' => ['integer'],
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'direction_id' => $data['direction_id'] ?? null,
            'topic' => $data['topic'],
            'description' => $data['description'] ?? null,
            'starts_at' => Carbon::parse($data['date'].' '.$data['time']),
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'type' => $data['type'],
            'capacity' => $data['capacity'],
            'trainer_id' => $data['trainer_id'] ?? null,
        ];
    }

    /** Отменить занятие с причиной. */
    public function cancel(Request $request, ClassSession $session, BookingService $bookings): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $bookings->cancelClass($session, $data['reason']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Занятие отменено, клиенты уведомлены.']);
    }
}
