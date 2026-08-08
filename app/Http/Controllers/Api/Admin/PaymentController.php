<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\RussianDate;
use Illuminate\Http\JsonResponse;

/**
 * Оплаты студии.
 */
class PaymentController extends Controller
{
    /** Последние оплаты. */
    public function index(): JsonResponse
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
