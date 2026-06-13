<?php

namespace App\Filament\Resources\PricingCatalogItems\Pages;

use App\Filament\Resources\PricingCatalogItems\PricingCatalogItemResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPricingCatalogItem extends EditRecord
{
    protected static string $resource = PricingCatalogItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
