<?php

namespace App\Filament\Resources\Subscriptions;

use App\Enums\SubscriptionType;
use App\Filament\Resources\Subscriptions\Pages\CreateSubscription;
use App\Filament\Resources\Subscriptions\Pages\EditSubscription;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Models\Subscription;
use App\Models\User;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static ?string $navigationLabel = 'Абонементы';

    protected static ?string $modelLabel = 'абонемент';

    protected static ?string $pluralModelLabel = 'абонементы';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Клиент и тип')
                    ->schema([
                        Select::make('user_id')
                            ->label('Клиент')
                            ->relationship(
                                name: 'user',
                                titleAttribute: 'last_name',
                                modifyQueryUsing: fn ($query) => $query->where('role', 'client'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (User $record) => $record->fullName().($record->formattedPhone() ? ' · '.$record->formattedPhone() : ''))
                            ->searchable(['last_name', 'first_name', 'phone', 'email'])
                            ->preload()
                            ->required()
                            ->live(),
                        Placeholder::make('client_health_note')
                            ->label('Ограничения по здоровью')
                            ->content(function (Get $get, ?Subscription $record): string {
                                $userId = $get('user_id') ?? $record?->user_id;

                                if (! $userId) {
                                    return '—';
                                }

                                $note = User::query()->whereKey($userId)->value('health_note');

                                return filled($note) ? $note : 'Не указано';
                            })
                            ->visible(fn (Get $get, ?Subscription $record): bool => filled($get('user_id') ?? $record?->user_id))
                            ->columnSpanFull(),
                        Select::make('type')
                            ->label('Тип абонемента')
                            ->options(collect(SubscriptionType::cases())->mapWithKeys(
                                fn (SubscriptionType $type) => [$type->value => $type->label()]
                            )->all())
                            ->required()
                            ->native(false),
                    ]),
                Section::make('Занятия')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('sessions_total')
                                ->label('Всего занятий')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(999)
                                ->required(),
                            TextInput::make('sessions_used')
                                ->label('Списано')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->required()
                                ->maxValue(fn (Get $get): ?int => filled($get('sessions_total'))
                                    ? (int) $get('sessions_total')
                                    : null)
                                ->validationMessages([
                                    'max' => 'Списано не может быть больше, чем всего занятий.',
                                ]),
                        ]),
                        Select::make('sessions_per_day')
                            ->label('Списывать за один день')
                            ->options([
                                1 => '1 занятие',
                                2 => '2 занятия (двойной абонемент)',
                            ])
                            ->default(1)
                            ->required()
                            ->native(false)
                            ->helperText('Для абонемента «2 занятия в один день» за день использования спишутся оба занятия, даже если клиент пришёл только на одно.'),
                    ]),
                Section::make('Сроки')
                    ->schema([
                        DatePicker::make('purchased_at')
                            ->label('Дата приобретения')
                            ->required()
                            ->default(now()),
                        Grid::make(2)->schema([
                            DatePicker::make('starts_at')
                                ->label('Дата начала')
                                ->required()
                                ->default(now()),
                            DatePicker::make('ends_at')
                                ->label('Действует до')
                                ->required()
                                ->default(now()->addDays(29))
                                ->after('starts_at'),
                        ]),
                    ]),
                Section::make('Заметка')
                    ->schema([
                        Textarea::make('admin_note')
                            ->label('Заметка администратора')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.last_name')
                    ->label('Клиент')
                    ->formatStateUsing(fn ($record) => $record->user?->fullName())
                    ->searchable(['last_name', 'first_name'])
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (SubscriptionType $state) => $state->shortLabel()),
                TextColumn::make('sessions_remaining')
                    ->label('Остаток')
                    ->state(fn (Subscription $record) => $record->sessionsRemaining())
                    ->suffix(fn (Subscription $record) => ' / '.$record->sessions_total),
                TextColumn::make('sessions_per_day')
                    ->label('За день')
                    ->badge()
                    ->color('warning')
                    ->state(fn (Subscription $record) => $record->isDoublePerDay() ? '2 в день' : '')
                    ->toggleable(),
                TextColumn::make('starts_at')
                    ->label('Начало')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('До')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('purchased_at')
                    ->label('Куплен')
                    ->date('d.m.Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('ends_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options(collect(SubscriptionType::cases())->mapWithKeys(
                        fn (SubscriptionType $type) => [$type->value => $type->label()]
                    )->all()),
                SelectFilter::make('user_id')
                    ->label('Клиент')
                    ->relationship('user', 'last_name')
                    ->getOptionLabelFromRecordUsing(fn (User $record) => $record->fullName())
                    ->searchable()
                    ->preload(),
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
            'index' => ListSubscriptions::route('/'),
            'create' => CreateSubscription::route('/create'),
            'edit' => EditSubscription::route('/{record}/edit'),
        ];
    }
}
