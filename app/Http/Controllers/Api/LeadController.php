<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\LeadRequestMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LeadController extends Controller
{
    /** Заявка «Оставить заявку» с публичной части. */
    public function store(Request $request): JsonResponse
    {
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
            Log::error('API: не удалось отправить заявку', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Не удалось отправить заявку. Позвоните нам или напишите в Telegram.',
            ], 500);
        }

        return response()->json(['message' => 'Спасибо! Заявка отправлена, мы свяжемся с вами.']);
    }
}
