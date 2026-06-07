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

                <div>
                    <a
                        href="{{ $offerUrl }}"
                        target="_blank"
                        rel="noopener"
                        class="fi-btn fi-btn-size-md inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-500"
                    >
                        Открыть оферту
                    </a>
                </div>
            </div>
        @else
            <div class="text-center text-gray-500 dark:text-gray-400">
                <p class="text-base font-medium text-gray-950 dark:text-white">Оферта пока не загружена</p>
                <p class="mt-1 text-sm">Нажмите «Загрузить оферту» вверху страницы и выберите PDF-файл.</p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
