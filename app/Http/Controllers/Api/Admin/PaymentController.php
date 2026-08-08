<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\PhoneNormalizer;
use App\Support\RussianDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Реестр оплат: фильтр по статусу, поиск по клиенту, страницы.
 *
 * Раньше маршрут отдавал последние 50 без фильтров — при 200+ платежах на проде
 * найти в этой пачке нужный нельзя.
 */
class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,waiting_for_capture,succeeded,canceled'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $q = trim((string) ($data['q'] ?? ''));

        $payments = Payment::query()
            ->with('user')
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
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
            ->latest()
            ->paginate(perPage: 30, page: $data['page'] ?? 1);

        return response()->json([
            'data' => collect($payments->items())->map(fn (Payment $p) => [
                'id' => $p->id,
                'client' => $p->user?->fullName() ?? '—',
                'client_id' => $p->user_id,
                'amount' => $p->amount,
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'description' => $p->description,
                'date' => RussianDate::dayMonthYear($p->created_at),
                'paid_at' => $p->paid_at ? RussianDate::dayMonthYear($p->paid_at) : null,
            ])->values(),
            'meta' => self::meta($payments),
        ]);
    }

    /**
     * Общий вид постраничной выдачи для реестров приложения.
     *
     * @return array{page: int, per_page: int, total: int, has_more: bool}
     */
    public static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'has_more' => $paginator->hasMorePages(),
        ];
    }
}
