<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EditAdminProfile extends EditProfile
{
    protected static ?string $title = 'Смена пароля';

    public static function getLabel(): string
    {
        return 'Смена пароля';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Смена пароля')
                    ->description('Вход в админку — с главной страницы сайта по телефону и паролю.')
                    ->schema([
                        TextInput::make('currentPassword')
                            ->label('Текущий пароль')
                            ->password()
                            ->revealable()
                            ->autocomplete('current-password')
                            ->currentPassword(guard: Filament::getAuthGuard())
                            ->required()
                            ->dehydrated(false),
                        $this->getPasswordFormComponent()
                            ->label('Новый пароль')
                            ->required(),
                        $this->getPasswordConfirmationFormComponent()
                            ->required()
                            ->visible(fn (Get $get): bool => filled($get('password'))),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return filled($data['password'] ?? null)
            ? ['password' => $data['password']]
            : [];
    }

    protected function getSaveFormAction(): \Filament\Actions\Action
    {
        return parent::getSaveFormAction()->label('Сохранить пароль');
    }
}
