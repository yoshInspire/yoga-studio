<?php

namespace App\Filament\Resources\News;

use App\Filament\Resources\News\Pages\CreateNews;
use App\Filament\Resources\News\Pages\EditNews;
use App\Filament\Resources\News\Pages\ListNews;
use App\Models\News;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class NewsResource extends Resource
{
    protected static ?string $model = News::class;

    protected static ?string $navigationLabel = 'Новости';

    protected static ?string $modelLabel = 'новость';

    protected static ?string $pluralModelLabel = 'новости';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Публикация')
                    ->schema([
                        TextInput::make('title')
                            ->label('Заголовок')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('excerpt')
                            ->label('Краткое описание')
                            ->helperText('Показывается в списке новостей. Если пусто — берётся начало текста.')
                            ->rows(2)
                            ->maxLength(500),
                        Textarea::make('body')
                            ->label('Текст новости')
                            ->required()
                            ->rows(10)
                            ->columnSpanFull(),
                        FileUpload::make('image_path')
                            ->label('Изображение')
                            ->image()
                            ->disk('public')
                            ->directory('news')
                            ->imageEditor()
                            ->maxSize(8192),
                    ]),
                Section::make('Видимость')
                    ->schema([
                        Toggle::make('is_published')
                            ->label('Опубликовано')
                            ->default(false),
                        DateTimePicker::make('published_at')
                            ->label('Дата публикации')
                            ->seconds(false)
                            ->native(false)
                            ->default(now())
                            ->helperText('Новость появится на сайте после этой даты (если включена публикация).'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Фото')
                    ->disk('public')
                    ->square(),
                TextColumn::make('title')
                    ->label('Заголовок')
                    ->searchable()
                    ->limit(50)
                    ->sortable(),
                IconColumn::make('is_published')
                    ->label('Опубликовано')
                    ->boolean(),
                TextColumn::make('published_at')
                    ->label('Дата публикации')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('published_at', 'desc')
            ->filters([
                TernaryFilter::make('is_published')
                    ->label('Публикация'),
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
            'index' => ListNews::route('/'),
            'create' => CreateNews::route('/create'),
            'edit' => EditNews::route('/{record}/edit'),
        ];
    }
}
