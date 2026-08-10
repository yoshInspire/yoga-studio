<?php

namespace App\Http\Controllers;

use App\Support\LegalDocuments;
use App\Support\OfferDocument;
use App\Support\OfferStorage;
use App\Support\RussianDate;
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
        $blocks = OfferDocument::blocks();
        $pdfUpdatedAt = OfferStorage::updatedAt();

        return view('pages.legal.offer', [
            'pdfAvailable' => OfferStorage::exists(),
            'pdfUpdatedAt' => $pdfUpdatedAt?->translatedFormat('d F Y'),
            // Текст, собранный из загруженного PDF. Пустой массив — на
            // странице остаётся вёрстка, набранная руками 08.08.2026.
            'blocks' => $blocks,
            // Редакция страницы: когда текст пришёл из файла, честная дата —
            // дата файла, а не дата в конфиге (она про ручную вёрстку).
            'revision' => $blocks !== [] && $pdfUpdatedAt !== null
                ? RussianDate::dayMonthYear($pdfUpdatedAt)
                : LegalDocuments::revision('offer_revision'),
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
