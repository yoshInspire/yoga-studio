<?php

namespace App\Filament\Resources\Directions;

use App\Filament\Resources\Directions\Pages\CreateDirection;
use App\Filament\Resources\Directions\Pages\EditDirection;
use App\Filament\Resources\Directions\Pages\ListDirections;
use App\Models\Direction;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class DirectionResource extends Resource
{
    protected static ?string $model = Direction::class;

    protected static ?string $navigationLabel = 'Направления';

    protected static ?string $modelLabel = 'направление';

    protected static ?string $pluralModelLabel = 'направления';

    protected static ?int $navigationSort = 8;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основное')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('title')
                                ->label('Название')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Get $get, Set $set, ?string $state, ?string $old, string $operation): void {
                                    if ($operation !== 'create' || filled($get('slug'))) {
                                        return;
                                    }

                                    $set('slug', Str::slug($state ?? '', '-', 'ru'));
                                }),
                            TextInput::make('slug')
                                ->label('Код (slug)')
                                ->required()
                                ->maxLength(100)
                                ->unique(ignoreRecord: true)
                                ->helperText('Латиница, используется в ссылках и папке для фото.'),
                        ]),
                        Grid::make(3)->schema([
                            TextInput::make('num')
                                ->label('Номер на карточке')
                                ->required()
                                ->maxLength(4)
                                ->placeholder('01'),
                            TextInput::make('sort_order')
                                ->label('Порядок')
                                ->numeric()
                                ->minValue(0)
                                ->default(fn (): int => (int) Direction::query()->max('sort_order') + 1)
                                ->required(),
                            TextInput::make('tag')
                                ->label('Бейдж')
                                ->maxLength(120)
                                ->placeholder('Необязательно'),
                        ]),
                        Textarea::make('lead')
                            ->label('Описание')
                            ->required()
                            ->rows(6)
                            ->columnSpanFull(),
                    ]),
                Section::make('Фотографии')
                    ->schema([
                        FileUpload::make('cover_image_path')
                            ->label('Обложка')
                            ->image()
                            ->disk('public_web')
                            ->directory(fn (Get $get): string => 'images/directions/'.($get('slug') ?: 'draft'))
                            ->imageEditor()
                            ->maxSize(8192)
                            ->required(),
                        FileUpload::make('gallery_paths')
                            ->label('Галерея в окне «Подробнее»')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->disk('public_web')
                            ->directory(fn (Get $get): string => 'images/directions/'.($get('slug') ?: 'draft'))
                            ->imageEditor()
                            ->maxSize(8192)
                            ->helperText('Дополнительные фото для карусели. Обложка показывается первой.'),
                    ]),
                Section::make('Дополнительный текст')
                    ->collapsed()
                    ->schema([
                        Textarea::make('body')
                            ->label('Дополнительные абзацы')
                            ->rows(4)
                            ->formatStateUsing(fn ($state): string => is_array($state) ? implode("\n\n", array_filter($state)) : '')
                            ->dehydrateStateUsing(function (?string $state): array {
                                if (! filled($state)) {
                                    return [];
                                }

                                return array_values(array_filter(array_map(
                                    trim(...),
                                    preg_split('/\R\R+/', $state) ?: [],
                                )));
                            })
                            ->helperText('Каждый абзац — через пустую строку.'),
                        Textarea::make('benefits')
                            ->label('Список «Что это даёт»')
                            ->rows(4)
                            ->formatStateUsing(fn ($state): string => is_array($state) ? implode("\n", array_filter($state)) : '')
                            ->dehydrateStateUsing(function (?string $state): array {
                                if (! filled($state)) {
                                    return [];
                                }

                                return array_values(array_filter(array_map(
                                    trim(...),
                                    preg_split('/\R/', $state) ?: [],
                                )));
                            })
                            ->helperText('Каждый пункт — с новой строки.'),
                    ]),
                Section::make('Публикация')
                    ->schema([
                        Toggle::make('is_published')
                            ->label('Показывать на сайте')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),
                ImageColumn::make('cover_image_path')
                    ->label('Фото')
                    ->disk('public_web')
                    ->square(),
                TextColumn::make('num')
                    ->label('Номер')
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_published')
                    ->label('На сайте')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                TernaryFilter::make('is_published')
                    ->label('На сайте'),
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
            'index' => ListDirections::route('/'),
            'create' => CreateDirection::route('/create'),
            'edit' => EditDirection::route('/{record}/edit'),
        ];
    }
}
