<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\FamilyService;
use App\Services\PaymentService;
use App\Support\PurchaseCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use InvalidArgumentException;

class PurchaseController extends Controller
{
    public function index(Request $request, FamilyService $family): View
    {
        return view('pages.purchase', [
            'catalog' => PurchaseCatalog::groupedOnlineProductsFor($request->user()),
            // Кому можно купить в подарок — участники семьи.
            'relatives' => $family->bookableRelatives($request->user()),
        ]);
    }

    public function store(Request $request, PaymentService $payments): RedirectResponse
    {
        $validated = $request->validate([
            'product_key' => ['required', 'string', 'max:64'],
            'starts_at' => ['required', 'date'],
            'recipient_id' => ['nullable', 'integer'],
        ]);

        try {
            $payment = $payments->initiate(
                $request->user(),
                $validated['product_key'],
                Carbon::parse($validated['starts_at']),
                $this->recipient($validated['recipient_id'] ?? null),
            );
        } catch (InvalidArgumentException $e) {
            return back()
                ->withInput()
                ->withErrors(['purchase' => $e->getMessage()]);
        }

        if ($payment->confirmation_url === null) {
            return back()->withErrors([
                'purchase' => 'Не удалось получить ссылку на оплату. Попробуйте позже или обратитесь в студию.',
            ]);
        }

        return redirect()->away($payment->confirmation_url);
    }

    /** Получатель подарка; чужой id проверит `PaymentService::giftBlocker()`. */
    private function recipient(?int $id): ?User
    {
        if ($id === null) {
            return null;
        }

        return User::query()->find($id)
            ?? throw new InvalidArgumentException('Подарить абонемент можно только участнику своей семьи.');
    }
}
