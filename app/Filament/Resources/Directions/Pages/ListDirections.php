<?php

namespace App\Filament\Resources\Directions\Pages;

use App\Filament\Resources\Directions\DirectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDirections extends ListRecords
{
    protected static string $resource = DirectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
