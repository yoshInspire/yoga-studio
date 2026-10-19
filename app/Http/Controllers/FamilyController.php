<?php

namespace App\Http\Controllers;

use App\Models\FamilyInvite;
use App\Models\Subscription;
use App\Models\User;
use App\Services\FamilyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Семья на сайте: страница приглашения и действия из раздела «Семья»
 * личного кабинета. Логика — в `FamilyService`, общая с приложением.
 */
class FamilyController extends Controller
{
    /** Ключ сессии: приглашение, открытое до входа, — кабинет напомнит о нём. */
    public const PENDING_INVITE_KEY = 'family_invite_code';

    public function __construct(private FamilyService $family) {}

    /**
     * Страница по ссылке-приглашению.
     *
     * Только показывает: мессенджеры открывают ссылки сами ради превью, и
     * вступление на GET срабатывало бы без участия человека. Вступают кнопкой
     * (POST). Гостю запоминаем ссылку — после входа он вернётся сюда же.
     */
    public function show(Request $request, ?string $code = null): View
    {
        // Без кода в адресе — его ввели в форме «Есть код приглашения?».
        $invite = $this->family->findInvite($code ?? (string) $request->query('code', ''));
        $user = $request->user();

        if ($invite !== null && $user === null) {
            $request->session()->put('url.intended', $request->fullUrl());
            $request->session()->put(self::PENDING_INVITE_KEY, $invite->code);
        }

        return view('pages.family-join', [
            'invite' => $invite,
            'preview' => $invite !== null ? $this->family->presentInvitePreview($invite, $user) : null,
            'appUrl' => $invite !== null ? 'ekoyoga://family/join/'.$invite->code : null,
            'user' => $user,
        ]);
    }

    public function accept(Request $request, string $code): RedirectResponse
    {
        $invite = $this->family->findInvite($code);

        if ($invite === null) {
            return back()->withErrors(['family' => 'Приглашение не найдено.']);
        }

        try {
            $this->family->accept($invite, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['family' => $e->getMessage()]);
        }

        $request->session()->forget(self::PENDING_INVITE_KEY);

        return $this->toFamily('Вы в семье! Если владелец откроет вам абонемент, по нему можно будет записываться.');
    }

    public function createInvite(Request $request): RedirectResponse
    {
        return $this->attempt(function () use ($request) {
            $invite = $this->family->createInvite($request->user());
            session()->flash('family_new_invite', $invite->id);

            return 'Приглашение готово — скопируйте ссылку и отправьте её.';
        });
    }

    public function revokeInvite(Request $request, FamilyInvite $invite): RedirectResponse
    {
        return $this->attempt(function () use ($request, $invite) {
            $this->family->revokeInvite($request->user(), $invite);

            return 'Приглашение отозвано.';
        });
    }

    public function removeMember(Request $request, User $member): RedirectResponse
    {
        return $this->attempt(function () use ($request, $member) {
            $this->family->removeMember($request->user(), $member);

            return $member->shortName().' больше не в семье.';
        });
    }

    public function transferOwnership(Request $request, User $member): RedirectResponse
    {
        return $this->attempt(function () use ($request, $member) {
            $this->family->transferOwnership($request->user(), $member);

            return 'Главой семьи теперь '.$member->shortName().'.';
        });
    }

    public function leave(Request $request): RedirectResponse
    {
        return $this->attempt(function () use ($request) {
            $this->family->leave($request->user());

            return 'Вы вышли из семьи.';
        });
    }

    public function dissolve(Request $request): RedirectResponse
    {
        return $this->attempt(function () use ($request) {
            $this->family->dissolve($request->user());

            return 'Семья распущена.';
        });
    }

    public function updateShares(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'user_ids' => ['nullable', 'array', 'max:'.FamilyService::MAX_MEMBERS],
            'user_ids.*' => ['integer'],
        ]);

        return $this->attempt(function () use ($request, $subscription, $data) {
            $ids = $data['user_ids'] ?? [];
            $this->family->setShares($request->user(), $subscription, $ids);

            return $ids === [] ? 'Абонемент больше никому не открыт.' : 'Доступ к абонементу сохранён.';
        });
    }

    /**
     * @param  callable(): string  $action
     */
    private function attempt(callable $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (InvalidArgumentException $e) {
            return redirect()->route('account')->with('lk_section', 'family')->withErrors(['family' => $e->getMessage()]);
        }

        return $this->toFamily($message);
    }

    private function toFamily(string $message): RedirectResponse
    {
        return redirect()->route('account')->with('lk_section', 'family')->with('family_status', $message);
    }
}
