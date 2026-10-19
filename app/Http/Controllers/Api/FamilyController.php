<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FamilyInvite;
use App\Models\Subscription;
use App\Models\User;
use App\Services\FamilyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Семья в приложении клиента. Вся логика — в `FamilyService`, здесь только
 * разбор запроса и ответы. Каждое изменение отвечает свежим `overview`,
 * чтобы экрану не нужен был второй запрос.
 */
class FamilyController extends Controller
{
    public function __construct(private FamilyService $family) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->family->overview($request->user()));
    }

    public function createInvite(Request $request): JsonResponse
    {
        return $this->attempt($request, function () use ($request) {
            $invite = $this->family->createInvite($request->user());

            return [
                'message' => 'Приглашение готово. Оно действует '.FamilyService::INVITE_TTL_DAYS.' дней и только для одного человека.',
                'invite' => $this->family->presentInvite($invite),
            ];
        });
    }

    public function revokeInvite(Request $request, FamilyInvite $invite): JsonResponse
    {
        return $this->attempt($request, function () use ($request, $invite) {
            $this->family->revokeInvite($request->user(), $invite);

            return ['message' => 'Приглашение отозвано.'];
        });
    }

    /** Экран «Вас приглашают в семью»: кто зовёт и можно ли вступить. */
    public function previewInvite(Request $request, string $code): JsonResponse
    {
        $invite = $this->family->findInvite($code);

        if ($invite === null) {
            return response()->json(['message' => 'Приглашение не найдено. Проверьте код.'], 404);
        }

        return response()->json($this->family->presentInvitePreview($invite, $request->user()));
    }

    public function acceptInvite(Request $request, string $code): JsonResponse
    {
        $invite = $this->family->findInvite($code);

        if ($invite === null) {
            return response()->json(['message' => 'Приглашение не найдено. Проверьте код.'], 404);
        }

        return $this->attempt($request, function () use ($request, $invite) {
            $this->family->accept($invite, $request->user());

            return ['message' => 'Вы в семье! Если владелец откроет вам абонемент, по нему можно будет записываться.'];
        });
    }

    public function removeMember(Request $request, User $member): JsonResponse
    {
        return $this->attempt($request, function () use ($request, $member) {
            $this->family->removeMember($request->user(), $member);

            return ['message' => $member->shortName().' больше не в семье.'];
        });
    }

    public function transferOwnership(Request $request, User $member): JsonResponse
    {
        return $this->attempt($request, function () use ($request, $member) {
            $this->family->transferOwnership($request->user(), $member);

            return ['message' => 'Главой семьи теперь '.$member->shortName().'.'];
        });
    }

    public function leave(Request $request): JsonResponse
    {
        return $this->attempt($request, function () use ($request) {
            $this->family->leave($request->user());

            return ['message' => 'Вы вышли из семьи.'];
        });
    }

    public function dissolve(Request $request): JsonResponse
    {
        return $this->attempt($request, function () use ($request) {
            $this->family->dissolve($request->user());

            return ['message' => 'Семья распущена.'];
        });
    }

    /** Кому из семьи открыт абонемент: полный список, остальным доступ закрывается. */
    public function updateShares(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:'.FamilyService::MAX_MEMBERS],
            'user_ids.*' => ['integer'],
        ]);

        return $this->attempt($request, function () use ($request, $subscription, $data) {
            $this->family->setShares($request->user(), $subscription, $data['user_ids']);

            return ['message' => $data['user_ids'] === []
                ? 'Абонемент больше никому не открыт.'
                : 'Доступ к абонементу сохранён.'];
        });
    }

    /**
     * @param  callable(): array<string, mixed>  $action
     */
    private function attempt(Request $request, callable $action): JsonResponse
    {
        try {
            $result = $action();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result + ['family' => $this->family->overview($request->user())]);
    }
}
