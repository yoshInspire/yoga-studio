<?php

namespace App\Http\Controllers;

use App\Mail\LeadRequestMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LeadController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        // Honeypot: бот заполнит скрытое поле — тихо игнорируем.
        if (filled($request->input('company'))) {
            return back()->with('lead_status', 'Спасибо! Заявка отправлена, мы свяжемся с вами.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'name' => 'имя',
            'phone' => 'телефон',
            'message' => 'комментарий',
        ]);

        try {
            Mail::to(config('studio.lead_email'))->send(new LeadRequestMail(
                leadName: $data['name'],
                leadPhone: $data['phone'],
                leadMessage: $data['message'] ?? null,
            ));
        } catch (\Throwable $e) {
            Log::error('Не удалось отправить заявку с сайта', [
                'error' => $e->getMessage(),
                'lead' => $data,
            ]);

            return back()
                ->withInput()
                ->with('lead_error', 'Не удалось отправить заявку. Позвоните нам или напишите в Telegram.');
        }

        return back()->with('lead_status', 'Спасибо! Заявка отправлена, мы свяжемся с вами.');
    }
}
