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
use App\Support\RussianDate;
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
use Filament\Tables\Grouping\Group;
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
                        Select::make('direction_id')
                            ->label('Направление')
                            ->relationship(
                                name: 'direction',
                                titleAttribute: 'title',
                                modifyQueryUsing: fn ($query) => $query->ordered(),
                            )
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Направление студии, например Power Pilates. Для мероприятий можно не указывать.'),
                        TextInput::make('topic')
                            ->label('Тема занятия')
                            ->required()
                            ->maxLength((int) config('studio.class_title_max_length', 120))
                            ->helperText('Тема на этот день, например «Создание рельефа» или «Мобильность плеч».'),
                        Textarea::make('description')
                            ->label('Описание')
                            ->rows(3)
                            ->columnSpanFull(),
                        DateTimePicker::make('starts_at')
                            ->label('Дата и время начала')
                            ->required()
                            ->seconds(false)
                            ->native(false),
                        Grid::make(3)->schema([
                            Select::make('type')
                                ->label('Тип')
                                ->options(collect(SubscriptionType::cases())->mapWithKeys(
                                    fn (SubscriptionType $type) => [$type->value => $type->label()]
                                )->all())
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn ($state, callable $set) => $set(
                                    'duration_minutes',
                                    ClassSession::defaultDurationMinutesForType($state),
                                ))
                                ->native(false),
                            TextInput::make('duration_minutes')
                                ->label('Длительность')
                                ->numeric()
                                ->minValue(15)
                                ->maxValue(300)
                                ->suffix('мин')
                                ->default(fn ($get) => ClassSession::defaultDurationMinutesForType($get('type')))
                                ->required()
                                ->helperText('Сколько минут длится занятие. Показывается в расписании на сайте.'),
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
                    ->label('Время')
                    ->dateTime('H:i')
                    ->sortable(),
                TextColumn::make('duration_minutes')
                    ->label('Длительность')
                    ->formatStateUsing(fn (?int $state, ClassSession $record) => ($state ?? $record->durationMinutes()).' мин')
                    ->sortable(),
                TextColumn::make('direction.title')
                    ->label('Направление')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('topic')
                    ->label('Тема')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('В расписании')
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ->groups([
                Group::make('starts_at')
                    ->label('День')
                    ->date()
                    ->collapsible()
                    ->titlePrefixedWithLabel(false)
                    ->getTitleFromRecordUsing(function (ClassSession $record): string {
                        $date = $record->starts_at;

                        if ($date->isToday()) {
                            return 'Сегодня · '.RussianDate::weekdayShortDayMonth($date);
                        }

                        return RussianDate::weekdayShortDayMonth($date);
                    }),
            ])
            ->defaultGroup('starts_at')
            ->groupingSettingsHidden()
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
            'index' => ListClassSessions::route('/'),
            'create' => CreateClassSession::route('/create'),
            'edit' => EditClassSession::route('/{record}/edit'),
        ];
    }
}
