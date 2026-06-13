<?php

namespace App\Filament\Pages;

use App\Models\ProductPrice;
use App\Support\PurchaseCatalog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\CanUseDatabaseTransactions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Throwable;

class Pricing extends Page
{
    use CanUseDatabaseTransactions;

    protected static ?string $navigationLabel = 'Цены';

    protected static ?string $title = 'Цены и тарифы';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $data = [];

        foreach (array_keys(config('purchases.products', [])) as $key) {
            $data[$key] = PurchaseCatalog::price($key);
        }

        $this->form->fill($data);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->operation('edit')
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        $components = [];

        foreach (config('purchases.categories', []) as $categoryKey => $categoryLabel) {
            $fields = [];

            foreach (config('purchases.products', []) as $key => $product) {
                if (($product['category'] ?? null) !== $categoryKey) {
                    continue;
                }

                $fields[] = TextInput::make($key)
                    ->label((string) $product['name'])
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(999999)
                    ->required()
                    ->suffix('₽');
            }

            if ($fields !== []) {
                $components[] = Section::make($categoryLabel)
                    ->description('Базовые тарифы. Дополнительные позиции (мероприятия, отдельные услуги) — в разделе «Доп. тарифы». Цены отображаются на главной, в разделе покупки и при онлайн-оплате.')
                    ->schema($fields);
            }
        }

        return $schema->components($components);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->fullWidth($this->hasFullWidthFormActions())
                    ->sticky($this->areFormActionsSticky())
                    ->key('form-actions'),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return Action::make('save')
            ->label('Сохранить цены')
            ->submit('save')
            ->keyBindings(['mod+s']);
    }

    protected function hasFullWidthFormActions(): bool
    {
        return false;
    }

    public function save(): void
    {
        try {
            $this->beginDatabaseTransaction();

            $data = $this->form->getState();

            foreach ($data as $key => $price) {
                ProductPrice::query()->updateOrCreate(
                    ['product_key' => (string) $key],
                    ['price' => (int) $price],
                );
            }

            PurchaseCatalog::forgetPrices();
        } catch (Halt $exception) {
            $exception->shouldRollbackDatabaseTransaction() ?
                $this->rollBackDatabaseTransaction() :
                $this->commitDatabaseTransaction();

            return;
        } catch (Throwable $exception) {
            $this->rollBackDatabaseTransaction();

            throw $exception;
        }

        $this->commitDatabaseTransaction();

        Notification::make()
            ->title('Цены сохранены')
            ->body('Новые цены отображаются на сайте и используются при онлайн-оплате.')
            ->success()
            ->send();
    }
}
