<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\SubscriptionBalanceService;
use App\Services\SubscriptionService;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Абонементы студии: выдача вручную, правка и продление.
 *
 * Удаления здесь нет намеренно: на абонементе висят записи и списания, и снос
 * строки оставил бы историю посещений без основания. Ошибочный абонемент
 * приводят в порядок правкой.
 */
class SubscriptionController extends Controller
{
    /**
     * Реестр абонементов: тип, состояние, поиск по клиенту, страницы.
     *
     * Состояние считаем в запросе, а не по `isActive()` в памяти: иначе
     * фильтр пришлось бы применять после пагинации, и страницы поехали бы.
     */
    public function index(Request $request, SubscriptionBalanceService $balances): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:group,individual,special_event'],
            'state' => ['nullable', 'in:active,expired,future'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $q = trim((string) ($data['q'] ?? ''));
        $today = now()->startOfDay();

        $subscriptions = Subscription::query()
            ->with('user')
            ->when($data['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($data['state'] ?? null, fn ($query, $state) => match ($state) {
                'active' => $query->active($today),
                'expired' => $query->where('ends_at', '<', $today),
                'future' => $query->where('starts_at', '>', $today),
                default => $query,
            })
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
            ->orderByDesc('ends_at')
            ->paginate(perPage: 30, page: $data['page'] ?? 1);

        $breakdowns = $balances->breakdownForMany(collect($subscriptions->items()));

        return response()->json([
            'data' => collect($subscriptions->items())->map(fn (Subscription $s) => [
                'id' => $s->id,
                'client' => $s->user?->fullName() ?? '—',
                'client_id' => $s->user_id,
                'type' => $s->type->value,
                'type_short' => $s->type->shortLabel(),
                'sessions_total' => $s->sessions_total,
                'sessions_remaining' => $breakdowns[$s->id]['sessions_remaining'],
                'sessions_per_day' => $s->sessionsPerDay(),
                'starts_at' => $s->starts_at->toDateString(),
                'ends_at' => $s->ends_at->toDateString(),
                'is_active' => $s->isActive(),
            ])->values(),
            'meta' => PaymentController::meta($subscriptions),
        ]);
    }

    /** Выдать абонемент клиенту. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', 'in:group,individual,special_event'],
            'sessions_total' => ['required', 'integer', 'between:1,100'],
            'sessions_per_day' => ['nullable', 'integer', 'in:1,2'],
            'starts_at' => ['required', 'date'],
            'validity_days' => ['nullable', 'integer', 'between:1,365'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $startsAt = Carbon::parse($data['starts_at'])->startOfDay();
        $endsAt = $startsAt->copy()->addDays($data['validity_days'] ?? 30);

        Subscription::query()->create([
            'user_id' => $data['user_id'],
            'type' => $data['type'],
            'sessions_total' => $data['sessions_total'],
            'sessions_used' => 0,
            'sessions_per_day' => $data['sessions_per_day'] ?? 1,
            'purchased_at' => now(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'admin_note' => $data['admin_note'] ?? 'Выдан администратором через приложение',
        ]);

        return response()->json(['message' => 'Абонемент выдан.']);
    }

    /**
     * Правка абонемента — те же поля, что в форме админки.
     *
     * «Списано» правится руками сознательно: в вебе так же, это единственный
     * способ починить перекос после ручных операций. Меньше числа фактически
     * зарезервированных занятий поставить нельзя — иначе остаток уйдёт в минус.
     */
    public function update(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:group,individual,special_event'],
            'sessions_total' => ['required', 'integer', 'between:1,999'],
            'sessions_used' => ['required', 'integer', 'min:0', 'lte:sessions_total'],
            'sessions_per_day' => ['required', 'integer', 'in:1,2'],
            'purchased_at' => ['required', 'date'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ], [
            'sessions_used.lte' => 'Списано не может быть больше, чем всего занятий.',
            'ends_at.after_or_equal' => 'Дата окончания не может быть раньше даты начала.',
        ]);

        $subscription->update([
            'type' => $data['type'],
            'sessions_total' => $data['sessions_total'],
            'sessions_used' => $data['sessions_used'],
            'sessions_per_day' => $data['sessions_per_day'],
            'purchased_at' => Carbon::parse($data['purchased_at'])->startOfDay(),
            'starts_at' => Carbon::parse($data['starts_at'])->startOfDay(),
            'ends_at' => Carbon::parse($data['ends_at'])->startOfDay(),
            'admin_note' => $data['admin_note'] ?? null,
        ]);

        return response()->json(['message' => 'Абонемент обновлён.']);
    }

    /**
     * Продлить на дни. Считает сервис: он же дописывает строку в заметку,
     * поэтому в карточке видно, когда и на сколько продлевали.
     */
    public function extend(Request $request, Subscription $subscription, SubscriptionService $subscriptions): JsonResponse
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'between:1,365'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $subscription = $subscriptions->extendByDays($subscription, (int) $data['days'], $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Абонемент действует до '.$subscription->ends_at->format('d.m.Y').'.',
        ]);
    }

    /** Добавить занятий в абонемент. */
    public function addSessions(Request $request, Subscription $subscription, SubscriptionService $subscriptions): JsonResponse
    {
        $data = $request->validate(['count' => ['required', 'integer', 'between:1,100']]);

        try {
            $subscription = $subscriptions->addSessions($subscription, (int) $data['count']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Теперь занятий: '.$subscription->sessions_total.', остаток '.$subscription->sessionsRemaining().'.',
        ]);
    }
}
