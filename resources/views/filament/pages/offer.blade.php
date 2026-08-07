<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        @if ($offerExists)
            <div class="flex flex-col gap-4">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-primary-50 text-sm font-semibold text-primary-600 dark:bg-primary-500/10">
                        PDF
                    </span>
                    <div>
                        <p class="text-base font-semibold text-gray-950 dark:text-white">Договор публичной оферты</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Загружен: {{ $updatedAt }} · просмотр без прямого скачивания
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <a
                        href="{{ $offerUrl }}"
                        target="_blank"
                        rel="noopener"
                        class="fi-btn fi-btn-size-md inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-500"
                    >
                        Открыть страницу оферты
                    </a>
                    <a
                        href="{{ $pdfUrl }}"
                        target="_blank"
                        rel="noopener"
                        class="fi-btn fi-btn-size-md inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-950/10 hover:bg-gray-50 dark:text-gray-200 dark:ring-white/20"
                    >
                        Открыть загруженный PDF
                    </a>
                </div>

                <p class="rounded-lg bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">
                    Клиенты читают <b>текстовую версию</b> договора на странице сайта: PDF в браузере
                    телефона на Android не открывается, а скачивается файлом. Загруженный сюда PDF —
                    оригинал документа, ссылка на него стоит на той же странице. Если вы заменили PDF
                    новой редакцией, попросите разработчика обновить и текст на странице, иначе
                    версии разойдутся.
                </p>
            </div>
        @else
            <div class="text-center text-gray-500 dark:text-gray-400">
                <p class="text-base font-medium text-gray-950 dark:text-white">Оферта пока не загружена</p>
                <p class="mt-1 text-sm">Нажмите «Загрузить оферту» вверху страницы и выберите PDF-файл.</p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
