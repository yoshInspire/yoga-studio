<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Support\PhoneNormalizer;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'Пользователи';

    protected static ?string $modelLabel = 'пользователь';

    protected static ?string $pluralModelLabel = 'пользователи';

    protected static ?string $recordTitleAttribute = 'last_name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Личные данные')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('last_name')
                                ->label('Фамилия')
                                ->required()
                                ->maxLength(100),
                            TextInput::make('first_name')
                                ->label('Имя')
                                ->required()
                                ->maxLength(100),
                        ]),
                        TextInput::make('patronymic')
                            ->label('Отчество')
                            ->maxLength(100),
                        Grid::make(3)->schema([
                            TextInput::make('birth_day')
                                ->label('День')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(31),
                            TextInput::make('birth_month')
                                ->label('Месяц')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(12),
                            TextInput::make('birth_year')
                                ->label('Год')
                                ->numeric()
                                ->minValue(1920)
                                ->maxValue(2026),
                        ]),
                    ]),
                Section::make('Ограничения по здоровью')
                    ->description('Видит только администратор. Хранится в карточке клиента и не привязано к абонементу.')
                    ->visible(fn (Get $get): bool => $get('role') === UserRole::Client->value)
                    ->schema([
                        Textarea::make('health_note')
                            ->label('Примечание')
                            ->rows(4)
                            ->maxLength(5000)
                            ->columnSpanFull(),
                        DateTimePicker::make('offer_accepted_at')
                            ->label('Оферта принята')
                            ->seconds(false)
                            ->native(false)
                            ->displayFormat('d.m.Y H:i')
                            ->helperText('Дата и время согласия с офертой. Для клиентов, заведённых вручную, можно указать здесь.'),
                    ]),
                Section::make('Контакты и доступ')
                    ->description(fn (Get $get): ?string => in_array($get('role'), [UserRole::Client->value, UserRole::Trainer->value], true)
                        ? 'После сохранения нажмите «Отправить доступ» — система сгенерирует пароль и отправит его на email (и в Telegram, если привязан).'
                        : null)
                    ->schema([
                        TextInput::make('phone')
                            ->label('Телефон')
                            ->required()
                            ->placeholder('+7 (___) ___-__-__')
                            ->maxLength(18)
                            ->extraInputAttributes([
                                'data-phone-mask' => true,
                                'inputmode' => 'tel',
                                'autocomplete' => 'tel',
                            ])
                            ->mutateStateForValidationUsing(fn (?string $state) => PhoneNormalizer::normalize($state) ?? $state)
                            ->dehydrateStateUsing(fn (?string $state) => PhoneNormalizer::normalize($state))
                            ->formatStateUsing(fn (?string $state) => PhoneNormalizer::format($state) ?? $state)
                            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                                if (! is_string($value) || strlen($value) !== 11 || PhoneNormalizer::normalize($value) === null) {
                                    $fail('Введите корректный номер телефона.');
                                }
                            })
                            ->unique(ignoreRecord: true),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Нужен для отправки временного пароля и уведомлений. Telegram администратор не привязывает — клиент может сделать это сам в личном кабинете после входа.'),
                        Select::make('role')
                            ->label('Роль')
                            ->options(collect(UserRole::cases())->mapWithKeys(
                                fn (UserRole $role) => [$role->value => $role->label()]
                            )->all())
                            ->required()
                            ->default(UserRole::Client->value)
                            ->live(),
                        TextInput::make('password')
                            ->label('Пароль')
                            ->password()
                            ->revealable()
                            ->visible(fn (Get $get): bool => $get('role') === UserRole::Admin->value)
                            ->required(fn (string $operation, Get $get): bool => $operation === 'create' && $get('role') === UserRole::Admin->value)
                            ->dehydrated(fn (?string $state, Get $get): bool => $get('role') === UserRole::Admin->value && filled($state))
                            ->minLength(8)
                            ->helperText('Только для администраторов. Клиентам и тренерам пароль высылается кнопкой «Отправить доступ».'),
                    ]),
                Section::make('Профиль на сайте')
                    ->description('Для пользователей с ролью «Тренер»: фото и описание на главной странице.')
                    ->visible(fn (Get $get): bool => $get('role') === UserRole::Trainer->value)
                    ->schema([
                        Toggle::make('show_on_site')
                            ->label('Показывать в блоке «Тренеры» на главной')
                            ->default(true),
                        TextInput::make('site_sort_order')
                            ->label('Порядок на сайте')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText('Меньшее число — выше в списке.'),
                        FileUpload::make('trainer_photo_path')
                            ->label('Фото')
                            ->image()
                            ->disk('public')
                            ->directory('trainers')
                            ->imageEditor()
                            ->maxSize(8192),
                        TextInput::make('trainer_title')
                            ->label('Подпись под именем')
                            ->maxLength(255)
                            ->placeholder('Например: Тренер · аэройога и силовые практики'),
                        Textarea::make('trainer_bio')
                            ->label('Описание')
                            ->rows(4)
                            ->maxLength(2000),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('last_name')
                    ->label('Фамилия')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('first_name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('Телефон')
                    ->formatStateUsing(fn (?string $state) => PhoneNormalizer::format($state) ?? '—')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('role')
                    ->label('Роль')
                    ->badge()
                    ->formatStateUsing(fn (UserRole $state) => $state->label()),
                TextColumn::make('health_note')
                    ->label('Здоровье')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => filled($state) ? 'Есть примечание' : '')
                    ->color(fn (?string $state) => filled($state) ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('offer_accepted_at')
                    ->label('Оферта')
                    ->formatStateUsing(fn (?string $state) => filled($state) ? 'Да' : 'Нет')
                    ->badge()
                    ->color(fn (?string $state) => filled($state) ? 'success' : 'warning')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Регистрация')
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('last_name')
            ->filters([
                SelectFilter::make('role')
                    ->label('Роль')
                    ->options(collect(UserRole::cases())->mapWithKeys(
                        fn (UserRole $role) => [$role->value => $role->label()]
                    )->all()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->stackedOnMobile();
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['last_name', 'first_name', 'phone', 'email'];
    }
}
