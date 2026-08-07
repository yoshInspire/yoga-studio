<?php

namespace App\Http\Controllers;

use App\Support\OfferStorage;
use Illuminate\Contracts\View\View;

/**
 * Публичные правовые документы: оферта, политика обработки персональных данных
 * и страница удаления аккаунта.
 *
 * Все три страницы обязаны открываться без авторизации: их проверяют
 * автоматические роботы App Store и Google Play, а мобильное приложение
 * показывает документы ещё до входа — иначе человек соглашается с текстом,
 * которого не видел.
 */
class LegalController extends Controller
{
    public function offer(): View
    {
        return view('pages.legal.offer', [
            'pdfAvailable' => OfferStorage::exists(),
            'pdfUpdatedAt' => OfferStorage::updatedAt()?->translatedFormat('d F Y'),
        ]);
    }

    public function privacy(): View
    {
        return view('pages.legal.privacy');
    }

    public function accountDelete(): View
    {
        return view('pages.legal.account-delete');
    }
}
