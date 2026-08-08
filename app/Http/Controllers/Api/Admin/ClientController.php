<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ClientAccessService;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Клиенты студии: поиск и создание с высылкой доступа.
 */
class ClientController extends Controller
{
    /** Поиск клиентов. */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $clients = User::query()
            ->where('role', UserRole::Client)
            ->when($q !== '', function ($query) use ($q) {
                $phone = PhoneNormalizer::normalize($q);
                $query->where(function ($sub) use ($q, $phone) {
                    $sub->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                    if ($phone) {
                        $sub->orWhere('phone', 'like', "%{$phone}%");
                    }
                });
            })
            ->orderBy('last_name')
            ->limit(50)
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->fullName(),
                'phone' => $u->formattedPhone() ?? $u->phone,
                'email' => $u->email,
                'active_subscriptions' => $u->subscriptions()->active()->count(),
            ]);

        return response()->json(['data' => $clients]);
    }

    /** Создать клиента и выслать доступ. */
    public function store(Request $request, ClientAccessService $access): JsonResponse
    {
        $request->merge(['phone' => PhoneNormalizer::normalize($request->input('phone'))]);
        if ($request->filled('email')) {
            $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'patronymic' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'size:11', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'birth_day' => ['nullable', 'integer', 'between:1,31'],
            'birth_month' => ['nullable', 'integer', 'between:1,12'],
        ], ['phone.size' => 'Введите корректный номер телефона.', 'phone.unique' => 'Этот телефон уже зарегистрирован.']);

        $user = User::query()->create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'patronymic' => $data['patronymic'] ?? null,
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'birth_day' => $data['birth_day'] ?? null,
            'birth_month' => $data['birth_month'] ?? null,
            'role' => UserRole::Client,
            'password' => Str::password(12),
        ]);

        $accessResult = null;
        if (filled($user->email)) {
            try {
                $accessResult = $access->sendTemporaryPassword($user);
            } catch (\Throwable $e) {
                $accessResult = ['error' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => 'Клиент создан'.($accessResult && ($accessResult['email'] ?? false) ? ', доступ выслан на email.' : '.'),
            'id' => $user->id,
        ]);
    }
}
