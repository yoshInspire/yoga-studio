<?php

namespace App\Filament\Resources\Bookings;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\User;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
                            ->visibleOn('create'),
                        Select::make('class_session_id')
                            ->label('Занятие')
                            ->options(fn () => ClassSession::query()
                                ->where('starts_at', '>=', now())
                                ->orderBy('starts_at')
                                ->get()
                                ->mapWithKeys(fn (ClassSession $s) => [
                                    $s->id => $s->starts_at->format('d.m.Y H:i').' — '.$s->title,
                                ])
                                ->all())
                            ->searchable()
                            ->required()
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
                TextColumn::make('user.last_name')
                    ->label('Клиент')
                    ->formatStateUsing(fn ($record) => $record->user?->fullName())
                    ->searchable(['users.last_name', 'users.first_name']),
                TextColumn::make('subscription.type')
                    ->label('Абонемент')
                    ->formatStateUsing(fn ($record) => $record->subscription?->type?->shortLabel() ?? '—'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (BookingStatus $state) => $state->label()),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
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
            ]);
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
