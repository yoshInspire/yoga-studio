<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use App\Support\PurchaseCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use InvalidArgumentException;

class PurchaseController extends Controller
{
    public function index(): View
    {
        return view('pages.purchase', [
            'catalog' => PurchaseCatalog::groupedOnlineProducts(),
        ]);
    }

    public function store(Request $request, PaymentService $payments): RedirectResponse
    {
        $validated = $request->validate([
            'product_key' => ['required', 'string', 'max:64'],
            'starts_at' => ['required', 'date'],
        ]);

        try {
            $payment = $payments->initiate(
                $request->user(),
                $validated['product_key'],
                Carbon::parse($validated['starts_at']),
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
}
