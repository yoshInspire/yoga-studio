<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use App\Support\PurchaseCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class PurchaseController extends Controller
{
    /** Каталог абонементов для онлайн-оплаты. */
    public function index(Request $request): JsonResponse
    {
        $grouped = PurchaseCatalog::groupedOnlineProductsFor($request->user());

        $data = [];
        foreach ($grouped as $category => $products) {
            $data[] = [
                'category' => $category,
                'products' => array_map(fn ($p) => [
                    'key' => $p['key'],
                    'name' => $p['name'],
                    'type' => $p['type']->value,
                    'type_label' => $p['type']->shortLabel(),
                    'sessions' => $p['sessions'],
                    'price' => $p['price'],
                    'validity_days' => $p['validity_days'],
                ], $products),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** Инициировать оплату → вернуть ссылку на форму YooKassa. */
    public function store(Request $request, PaymentService $payments): JsonResponse
    {
        // product_keys — несколько абонементов одним платежом (мобильное
        // приложение). product_key оставлен для совместимости: так ходит сайт
        // и старые версии приложения, которые ещё не обновились.
        $validated = $request->validate([
            'product_keys' => ['sometimes', 'array', 'min:1', 'max:10'],
            'product_keys.*' => ['required', 'string', 'max:64'],
            'product_key' => ['required_without:product_keys', 'string', 'max:64'],
            'starts_at' => ['required', 'date'],
        ]);

        try {
            $payment = $payments->initiate(
                $request->user(),
                $validated['product_keys'] ?? $validated['product_key'],
                Carbon::parse($validated['starts_at']),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($payment->confirmation_url === null) {
            return response()->json([
                'message' => 'Не удалось получить ссылку на оплату. Попробуйте позже или обратитесь в студию.',
            ], 502);
        }

        return response()->json([
            'confirmation_url' => $payment->confirmation_url,
            'payment_id' => $payment->id,
        ]);
    }
}
