<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\AvatarService;
use App\Support\PhotoValidation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Фотография профиля на сайте.
 *
 * Форма стоит и в кабинете клиента, и в кабинете тренера, поэтому возвращаемся
 * назад (`back()`), а не на фиксированный маршрут.
 */
class AvatarController extends Controller
{
    public function __construct(private AvatarService $avatars) {}

    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            PhotoValidation::rules(),
            PhotoValidation::messages(),
            PhotoValidation::attributes(),
        );

        try {
            $this->avatars->update($request->user(), $request->file(PhotoValidation::FIELD));
        } catch (RuntimeException $e) {
            return back()
                ->withErrors([PhotoValidation::FIELD => $e->getMessage()])
                ->with('lk_section', 'profile');
        }

        return back()
            ->with('status', 'Фотография обновлена.')
            ->with('lk_section', 'profile');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->avatars->remove($request->user());

        return back()
            ->with('status', 'Фотография удалена.')
            ->with('lk_section', 'profile');
    }
}
