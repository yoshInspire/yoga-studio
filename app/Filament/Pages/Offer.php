<?php

namespace App\Filament\Pages;

use App\Support\OfferStorage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class Offer extends Page
{
    protected static ?string $navigationLabel = 'Оферта';

    protected static ?string $title = 'Договор-оферта';

    protected static ?int $navigationSort = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected string $view = 'filament.pages.offer';

    public function getViewData(): array
    {
        return [
            'offerExists' => OfferStorage::exists(),
            'updatedAt' => OfferStorage::updatedAt()?->translatedFormat('d F Y, H:i'),
            'offerUrl' => route('legal.offer'),
            'pdfUrl' => route('legal.offer-pdf'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label(OfferStorage::exists() ? 'Заменить файл' : 'Загрузить оферту')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('primary')
                ->schema([
                    FileUpload::make('file')
                        ->label('PDF-файл оферты')
                        ->disk(OfferStorage::DISK)
                        ->directory('offer')
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(20480)
                        ->getUploadedFileNameForStorageUsing(fn () => 'contract.pdf')
                        ->required()
                        ->helperText('До 20 МБ. Файл будет доступен клиентам только для просмотра, без прямой ссылки на скачивание.'),
                ])
                ->action(function (): void {
                    Notification::make()
                        ->title('Оферта обновлена')
                        ->body('Документ доступен клиентам в личном кабинете.')
                        ->success()
                        ->send();
                }),
            Action::make('delete')
                ->label('Удалить')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->visible(fn () => OfferStorage::exists())
                ->requiresConfirmation()
                ->action(function (): void {
                    OfferStorage::delete();

                    Notification::make()
                        ->title('Оферта удалена')
                        ->success()
                        ->send();
                }),
        ];
    }
}
