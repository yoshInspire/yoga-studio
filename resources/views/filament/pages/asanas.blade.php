<x-filament-panels::page>
    <div class="as">
        @if ($mode === 'program')
            @include('filament.pages.asanas._program')
        @elseif ($mode === 'library')
            @include('filament.pages.asanas._library')
        @else
            @include('filament.pages.asanas._folders')
        @endif
    </div>

    @if ($drawingMode !== null)
        @include('filament.pages.asanas._draw')
    @endif

    @include('filament.pages.asanas._styles')
</x-filament-panels::page>
