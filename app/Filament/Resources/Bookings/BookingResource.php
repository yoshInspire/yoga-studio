<?php

namespace App\Filament\Resources\Bookings;

use App\Enums\BookingStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationLabel = 'Записи';

    protected static ?string $modelLabel = 'запись';

    protected static ?string $pluralModelLabel = 'записи';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Запись клиента')
                    ->schema([
                        Select::make('user_id')
                            ->label('Клиент')
                            ->relationship(
                                name: 'user',
                                titleAttribute: 'last_name',
                                modifyQueryUsing: fn ($query) => $query->where('role', UserRole::Client->value),
                            )
                            ->getOptionLabelFromRecordUsing(fn (User $record) => $record->fullName())
                            ->searchable(['last_name', 'first_name', 'phone'])
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn ($set) => $set('subscription_id', null))
                            ->visibleOn('create'),
                        Placeholder::make('client_health_note')
                            ->label('Ограничения по здоровью')
                            ->content(function (Get $get, ?Booking $record): string {
                                $userId = $get('user_id') ?? $record?->user_id;

                                if (! $userId) {
                                    return '—';
                                }

                                $note = User::query()->whereKey($userId)->value('health_note');

                                return filled($note) ? $note : 'Не указано';
                            })
                            ->visible(fn (Get $get, ?Booking $record): bool => filled($get('user_id') ?? $record?->user_id))
                            ->columnSpanFull()
                            ->visibleOn('create'),
                        Select::make('class_session_id')
                            ->label('Занятие')
                            ->options(fn () => ClassSession::query()
                                ->where('starts_at', '>=', now())
                                ->orderBy('starts_at')
                                ->get()
                                ->mapWithKeys(fn (ClassSession $s) => [
                                    $s->id => $s->starts_at->format('d.m.Y H:i').' — '.$s->title.' ('.$s->type->shortLabel().')',
                                ])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn ($set) => $set('subscription_id', null))
                            ->visibleOn('create'),
                        Select::make('subscription_id')
                            ->label('Абонемент для списания')
                            ->placeholder('Подобрать автоматически')
                            ->helperText('Оставьте пустым — система выберет подходящий абонемент. Или выберите вручную, с какого списать занятие.')
                            ->options(function ($get) {
                                $userId = $get('user_id');
                                $sessionId = $get('class_session_id');

                                if (! $userId || ! $sessionId) {
                                    return [];
                                }

                                $session = ClassSession::find($sessionId);
                                $user = User::find($userId);

                                if (! $session || ! $user) {
                                    return [];
                                }

                                return collect(app(SubscriptionService::class)->usableForUserOnDate(
                                    $user,
                                    $session->type,
                                    $session->starts_at,
                                ))->mapWithKeys(fn (Subscription $s) => [
                                    $s->id => $s->type->shortLabel()
                                        .' · остаток '.$s->sessionsRemaining().'/'.$s->sessions_total
                                        .' · с '.$s->starts_at->format('d.m.Y')
                                        .' до '.$s->ends_at->format('d.m.Y'),
                                ])->all();
                            })
                            ->searchable()
                            ->native(false)
                            ->visibleOn('create'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('classSession.starts_at')
                    ->label('Когда')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('classSession.title')
                    ->label('Занятие')
                    ->searchable(),
                TextColumn::make('classSession.type')
                    ->label('Тип занятия')
                    ->badge()
                    ->formatStateUsing(fn (?SubscriptionType $state) => $state?->shortLabel() ?? '—'),
                TextColumn::make('user.last_name')
                    ->label('Клиент')
                    ->formatStateUsing(fn ($record) => $record->user?->fullName())
                    ->searchable(['last_name', 'first_name']),
                TextColumn::make('subscription.type')
                    ->label('Абонемент')
                    ->formatStateUsing(fn ($record) => $record->subscription?->type?->shortLabel() ?? '—'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (BookingStatus $state) => $state->label()),
            ])
            ->defaultSort('classSession.starts_at', 'asc')
            ->groups([
                Group::make('class_session_id')
                    ->label('Занятие')
                    ->collapsible()
                    ->titlePrefixedWithLabel(false)
                    ->orderQueryUsing(function (Builder $query, string $direction): Builder {
                        return $query->orderBy(
                            ClassSession::query()
                                ->select('starts_at')
                                ->whereColumn('class_sessions.id', 'bookings.class_session_id'),
                            $direction,
                        );
                    })
                    ->getTitleFromRecordUsing(function (Booking $record): string {
                        $session = $record->classSession;

                        if ($session === null) {
                            return 'Без занятия';
                        }

                        return $session->starts_at->format('d.m.Y H:i')
                            .' · '.$session->type->shortLabel()
                            .' · '.$session->title
                            .($session->type === SubscriptionType::Individual
                                ? ' · '.$session->trainerName()
                                : '')
                            .' · '.$session->confirmedCount().' / '.$session->capacity;
                    }),
            ])
            ->defaultGroup('class_session_id')
            ->groupingSettingsHidden()
            ->collapsedGroupsByDefault()
            ->filters([
                Filter::make('session_date')
                    ->label('Дата занятия')
                    ->form([
                        DatePicker::make('date')
                            ->label('Дата')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['date'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'classSession',
                            fn (Builder $q) => $q->whereDate('starts_at', $data['date']),
                        );
                    }),
                SelectFilter::make('session_type')
                    ->label('Тип занятия')
                    ->options(collect(SubscriptionType::cases())->mapWithKeys(
                        fn (SubscriptionType $type) => [$type->value => $type->label()]
                    )->all())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'classSession',
                            fn (Builder $q) => $q->where('type', $data['value']),
                        );
                    }),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(collect(BookingStatus::cases())->mapWithKeys(
                        fn (BookingStatus $s) => [$s->value => $s->label()]
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['classSession.trainer', 'user', 'subscription']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookings::route('/'),
            'create' => CreateBooking::route('/create'),
            'edit' => EditBooking::route('/{record}/edit'),
        ];
    }
}
