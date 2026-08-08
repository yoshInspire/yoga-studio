<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Services\BookingService;
use App\Support\RussianDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            ->with(['trainer', 'direction'])
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
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateForm($request);

        $session = ClassSession::query()->create([
            ...$this->attributes($data),
            'status' => ClassSessionStatus::Scheduled,
        ]);

        return response()->json(['message' => 'Занятие создано: «'.$session->title.'».', 'id' => $session->id]);
    }

    /**
     * Изменить занятие.
     *
     * Отменить занятие этим маршрутом нельзя: отмена — это ещё возврат занятий
     * на абонементы и письма записавшимся, за это отвечает `cancel()`. Обратный
     * перевод в «по расписанию» разрешён — так снимают ошибочную отмену.
     */
    public function update(Request $request, ClassSession $session): JsonResponse
    {
        $data = $this->validateForm($request, [
            'status' => ['nullable', 'in:scheduled'],
        ]);

        $session->fill($this->attributes($data));

        if (($data['status'] ?? null) === 'scheduled' && $session->isCancelled()) {
            $session->status = ClassSessionStatus::Scheduled;
            $session->cancellation_reason = null;
        }

        $session->save();

        return response()->json(['message' => 'Занятие обновлено: «'.$session->title.'».']);
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
