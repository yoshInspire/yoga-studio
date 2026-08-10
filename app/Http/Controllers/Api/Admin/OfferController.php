<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\OfferService;
use App\Support\OfferDocument;
use App\Support\OfferStorage;
use App\Support\RussianDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Договор-оферта из приложения (ADMIN_PLAN_2.md, фаза L).
 *
 * Одна кнопка — «Загрузить PDF». Всё остальное делает сервер: текст страницы
 * `/oferta`, которую читают клиенты и роботы магазинов приложений,
 * пересобирается из самого файла (`OfferService`). Двух редакций одного
 * документа больше нет — и не может появиться.
 *
 * Экран показывает дату файла, дату собранного текста и число блоков: если
 * разбор поехал, это видно сразу, не открывая страницу.
 */
class OfferController extends Controller
{
    public function __construct(
        private OfferService $offer,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->state());
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ], [
            'file.required' => 'Выберите файл оферты.',
            'file.mimes' => 'Оферта загружается файлом PDF.',
            'file.max' => 'Файл больше 20 МБ.',
        ], ['file' => 'файл']);

        $result = $this->offer->replace($request->file('file'));

        return response()->json([
            ...$this->state(),
            'parsed' => $result['parsed'],
            'message' => $result['message'],
        ]);
    }

    public function destroy(): JsonResponse
    {
        $this->offer->delete();

        return response()->json([
            ...$this->state(),
            'message' => 'Оферта удалена. На странице сайта осталась прежняя редакция текста.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $pdfAt = OfferStorage::updatedAt();
        $textAt = OfferDocument::updatedAt();
        $blocks = OfferDocument::blocks();

        return [
            'exists' => OfferStorage::exists(),
            'pdf_updated_at' => $pdfAt === null ? null : RussianDate::dayMonthYear($pdfAt),
            'text_updated_at' => $textAt === null ? null : RussianDate::dayMonthYear($textAt),
            'blocks' => count($blocks),
            // Первые строки собранного текста — чтобы проверить разбор, не
            // открывая страницу целиком.
            'preview' => collect($blocks)->take(3)->map(fn (array $b) => $b['text'])->all(),
            // Текст страницы старше файла: разбор не удался, и клиенты видят
            // не ту редакцию, которую загрузили.
            'stale' => OfferStorage::exists()
                && $pdfAt !== null
                && ($textAt === null || $textAt->lt($pdfAt->copy()->subMinute())),
            'page_url' => route('legal.offer'),
            'pdf_url' => route('legal.offer-pdf'),
        ];
    }
}
