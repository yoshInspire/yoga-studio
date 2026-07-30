<x-filament-panels::page>
    <div class="as">
        @if ($mode === 'program')
            @include('filament.pages.asanas._program')
        @elseif ($mode === 'library')
            @include('filament.pages.asanas._library')
        @else
            @include('filament.pages.asanas._folders')
        @endif

        {{-- Внутри .as, чтобы наследовать переменные темы; позиционируется fixed. --}}
        @if ($drawingMode !== null)
            @include('filament.pages.asanas._draw')
        @endif
    </div>

    @include('filament.pages.asanas._styles')
</x-filament-panels::page>
