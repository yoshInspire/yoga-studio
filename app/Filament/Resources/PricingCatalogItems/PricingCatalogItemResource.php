<?php

namespace App\Filament\Resources\PricingCatalogItems;

use App\Enums\PricingCategory;
use App\Enums\SubscriptionType;
use App\Filament\Resources\PricingCatalogItems\Pages\CreatePricingCatalogItem;
use App\Filament\Resources\PricingCatalogItems\Pages\EditPricingCatalogItem;
use App\Filament\Resources\PricingCatalogItems\Pages\ListPricingCatalogItems;
use App\Models\PricingCatalogItem;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PricingCatalogItemResource extends Resource
{
    protected static ?string $model = PricingCatalogItem::class;

    protected static ?string $navigationLabel = 'Доп. тарифы';

    protected static ?string $modelLabel = 'доп. тариф';

    protected static ?string $pluralModelLabel = 'доп. тарифы';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Тариф')
                    ->description('Дополнительные позиции прайса: мероприятия в групповом разделе, отдельные услуги в индивидуальном. Изменение цены сразу отображается в разделе «Цены» на сайте.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Йога-нидра'),
                        Grid::make(2)->schema([
                            Select::make('category')
                                ->label('Раздел прайса')
                                ->options(collect(PricingCategory::cases())->mapWithKeys(
                                    fn (PricingCategory $category): array => [$category->value => $category->label()],
                                )->all())
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                    if ($state === PricingCategory::Group->value
                                        && $get('subscription_type') === SubscriptionType::Individual->value) {
                                        $set('subscription_type', SubscriptionType::Group->value);
                                    }

                                    if ($state === PricingCategory::Individual->value
                                        && $get('subscription_type') === SubscriptionType::SpecialEvent->value) {
                                        $set('subscription_type', SubscriptionType::Individual->value);
                                    }
                                }),
                            Select::make('subscription_type')
                                ->label('Тип абонемента')
                                ->options(function (Get $get): array {
                                    $category = $get('category');

                                    return match ($category) {
                                        PricingCategory::Group->value => [
                                            SubscriptionType::Group->value => SubscriptionType::Group->shortLabel(),
                                            SubscriptionType::SpecialEvent->value => SubscriptionType::SpecialEvent->shortLabel(),
                                        ],
                                        PricingCategory::Individual->value => [
                                            SubscriptionType::Individual->value => SubscriptionType::Individual->shortLabel(),
                                        ],
                                        default => collect(SubscriptionType::cases())->mapWithKeys(
                                            fn (SubscriptionType $type): array => [$type->value => $type->shortLabel()],
                                        )->all(),
                                    };
                                })
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                    if ($state === SubscriptionType::SpecialEvent->value
                                        && blank($get('section_title'))) {
                                        $set('section_title', 'Доп. мероприятия');
                                    }
                                }),
                        ]),
                        TextInput::make('section_title')
                            ->label('Подраздел в прайсе')
                            ->maxLength(120)
                            ->placeholder('Доп. мероприятия')
                            ->helperText('Необязательно. Если указано — позиция появится в отдельном подразделе таблицы цен.'),
                        Grid::make(3)->schema([
                            TextInput::make('price')
                                ->label('Цена')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(999999)
                                ->required()
                                ->suffix('₽'),
                            TextInput::make('sessions')
                                ->label('Занятий')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(100)
                                ->default(1)
                                ->required(),
                            TextInput::make('validity_days')
                                ->label('Срок действия, дней')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(365)
                                ->helperText('Для разовых услуг можно оставить 30.'),
                        ]),
                        Grid::make(3)->schema([
                            TextInput::make('sort_order')
                                ->label('Порядок')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->required(),
                            Toggle::make('active')
                                ->label('Показывать в прайсе')
                                ->default(true)
                                ->helperText('Снимите галочку, когда мероприятие завершено.'),
                            Toggle::make('online')
                                ->label('Доступно для онлайн-оплаты')
                                ->default(false)
                                ->helperText('Если включено — тариф появится на странице покупки абонемента.'),
                        ]),
                        Toggle::make('highlight')
                            ->label('Выделить строку')
                            ->default(false),
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
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->label('Раздел')
                    ->formatStateUsing(fn (PricingCategory $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('section_title')
                    ->label('Подраздел')
                    ->placeholder('—'),
                TextColumn::make('price')
                    ->label('Цена')
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, '', ' ').' ₽')
                    ->sortable(),
                TextColumn::make('subscription_type')
                    ->label('Тип')
                    ->formatStateUsing(fn (SubscriptionType $state): string => $state->shortLabel()),
                IconColumn::make('active')
                    ->label('В прайсе')
                    ->boolean(),
                IconColumn::make('online')
                    ->label('Онлайн')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                SelectFilter::make('category')
                    ->label('Раздел')
                    ->options(collect(PricingCategory::cases())->mapWithKeys(
                        fn (PricingCategory $category): array => [$category->value => $category->label()],
                    )->all()),
                TernaryFilter::make('active')
                    ->label('В прайсе'),
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
            'index' => ListPricingCatalogItems::route('/'),
            'create' => CreatePricingCatalogItem::route('/create'),
            'edit' => EditPricingCatalogItem::route('/{record}/edit'),
        ];
    }
}
