<?php

namespace App\Filament\Resources\ClassSessions;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Filament\Resources\ClassSessions\Pages\CreateClassSession;
use App\Filament\Resources\ClassSessions\Pages\EditClassSession;
use App\Filament\Resources\ClassSessions\Pages\ListClassSessions;
use App\Models\ClassSession;
use App\Models\User;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ClassSessionResource extends Resource
{
    protected static ?string $model = ClassSession::class;

    protected static ?string $navigationLabel = 'Занятия';

    protected static ?string $modelLabel = 'занятие';

    protected static ?string $pluralModelLabel = 'занятия';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Занятие')
                    ->schema([
                        TextInput::make('title')
                            ->label('Название / тема')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label('Описание')
                            ->rows(3)
                            ->columnSpanFull(),
                        DateTimePicker::make('starts_at')
                            ->label('Дата и время')
                            ->required()
                            ->seconds(false)
                            ->native(false),
                        Grid::make(2)->schema([
                            Select::make('type')
                                ->label('Тип')
                                ->options(collect(SubscriptionType::cases())->mapWithKeys(
                                    fn (SubscriptionType $type) => [$type->value => $type->label()]
                                )->all())
                                ->required()
                                ->live()
                                ->native(false),
                            TextInput::make('capacity')
                                ->label('Мест в группе')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(99)
                                ->default(fn ($get) => $get('type') === SubscriptionType::Individual->value ? 1 : config('studio.default_class_capacity'))
                                ->required(),
                        ]),
                        Select::make('trainer_id')
                            ->label('Тренер')
                            ->relationship(
                                name: 'trainer',
                                titleAttribute: 'last_name',
                                modifyQueryUsing: fn ($query) => $query->where('role', UserRole::Trainer->value),
                            )
                            ->getOptionLabelFromRecordUsing(fn (User $record) => $record->fullName())
                            ->searchable(['last_name', 'first_name'])
                            ->preload()
                            ->nullable(),
                        Select::make('status')
                            ->label('Статус')
                            ->options(collect(ClassSessionStatus::cases())->mapWithKeys(
                                fn (ClassSessionStatus $s) => [$s->value => $s->label()]
                            )->all())
                            ->default(ClassSessionStatus::Scheduled->value)
                            ->required()
                            ->visibleOn('edit')
                            ->native(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('starts_at')
                    ->label('Когда')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Занятие')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (SubscriptionType $state) => $state->shortLabel()),
                TextColumn::make('taken')
                    ->label('Записано')
                    ->state(fn (ClassSession $record) => $record->confirmedCount().' / '.$record->capacity),
                TextColumn::make('trainer.last_name')
                    ->label('Тренер')
                    ->formatStateUsing(fn ($record) => $record->trainerName()),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (ClassSessionStatus $state) => $state->label()),
            ])
            ->defaultSort('starts_at')
            ->filters([
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options(collect(SubscriptionType::cases())->mapWithKeys(
                        fn (SubscriptionType $type) => [$type->value => $type->label()]
                    )->all()),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(collect(ClassSessionStatus::cases())->mapWithKeys(
                        fn (ClassSessionStatus $s) => [$s->value => $s->label()]
                    )->all()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClassSessions::route('/'),
            'create' => CreateClassSession::route('/create'),
            'edit' => EditClassSession::route('/{record}/edit'),
        ];
    }
}
