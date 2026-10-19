<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Services\FamilyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Семья клиента из админки: добавить в семью, убрать, открыть абонемент.
 *
 * Для тех, кто просит по телефону и сам в кабинет не заходит. Правила те же,
 * что у клиента (`FamilyService`): убрали человека — его записи по
 * абонементам семьи отменяются с возвратом занятий владельцам.
 * Ответ — свежая семья клиента, чтобы карточка не делала второй запрос.
 */
class FamilyController extends Controller
{
    public function __construct(private FamilyService $family) {}

    /** Добавить `member_id` в семью клиента (семьи нет — клиент станет главой). */
    public function add(Request $request, User $client): JsonResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $member = User::query()->findOrFail($data['member_id']);

        return $this->attempt($client, function () use ($client, $member) {
            $this->family->addByAdmin($client, $member);

            return $member->shortName().' добавлен(а) в семью.';
        });
    }

    /** Убрать клиента из его семьи. */
    public function remove(User $client): JsonResponse
    {
        return $this->attempt($client, function () use ($client) {
            $this->family->removeByAdmin($client);

            return $client->shortName().' больше не в семье.';
        });
    }

    /** Кому из семьи владельца открыт абонемент — полный список. */
    public function shares(Request $request, Subscription $subscription): JsonResponse
    {
        $shares = FamilyService::sharesFromRequest($request);

        return $this->attempt($subscription->user, function () use ($subscription, $shares) {
            $this->family->setSharesByAdmin($subscription, $shares);

            return $shares === [] ? 'Абонемент больше никому не открыт.' : 'Доступ к абонементу сохранён.';
        });
    }

    /**
     * @param  callable(): string  $action
     */
    private function attempt(User $client, callable $action): JsonResponse
    {
        try {
            $message = $action();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $message,
            'family' => $this->family->overview($client),
        ]);
    }
}
