<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Абонементы, выданные студией вручную.
 */
class SubscriptionController extends Controller
{
    /** Выдать абонемент клиенту. */
    public function store(Request $request): JsonResponse
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
}
