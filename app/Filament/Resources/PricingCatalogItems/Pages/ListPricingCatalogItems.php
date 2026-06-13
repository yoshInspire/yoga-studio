<?php

namespace App\Filament\Resources\PricingCatalogItems\Pages;

use App\Filament\Resources\PricingCatalogItems\PricingCatalogItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPricingCatalogItems extends ListRecords
{
    protected static string $resource = PricingCatalogItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
