<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use InvalidArgumentException;

class PaymentController extends Controller
{
    public function return(Payment $payment, PaymentService $payments): View|RedirectResponse
    {
        abort_unless($payment->user_id === auth()->id(), 403);

        $payment = $payments->syncFromRemote($payment);

        if ($payment->status->isPaid() && $payment->isFulfilled()) {
            return view('pages.payment-result', [
                'payment' => $payment,
                'success' => true,
            ]);
        }

        if ($payment->status === PaymentStatus::Canceled) {
            return view('pages.payment-result', [
                'payment' => $payment,
                'success' => false,
            ]);
        }

        return view('pages.payment-result', [
            'payment' => $payment,
            'success' => false,
            'pending' => true,
        ]);
    }

    public function webhook(Request $request, PaymentService $payments): Response
    {
        try {
            $notification = app(\App\Services\YooKassaService::class)
                ->parseNotification($request->all());

            $payments->handleNotification($notification);
        } catch (InvalidArgumentException) {
            return response('Invalid notification', 400);
        }

        return response('OK', 200);
    }
}
