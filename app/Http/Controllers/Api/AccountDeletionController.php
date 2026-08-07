<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Удаление аккаунта из мобильного приложения.
 *
 * Требование App Store Review Guideline 5.1.1(v) и политики Google Play:
 * если в приложении можно завести учётную запись, из него же должно быть
 * можно её удалить — временного отключения недостаточно.
 */
class AccountDeletionController extends Controller
{
    public function __construct(private readonly AccountDeletionService $deletion) {}

    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'password' => ['required', 'string'],
        ], [
            'password.required' => 'Введите пароль.',
        ]);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Пароль указан неверно.'],
            ]);
        }

        $this->deletion->delete($user);

        return response()->json([
            'message' => 'Аккаунт удалён. Данные профиля стёрты.',
        ]);
    }
}
