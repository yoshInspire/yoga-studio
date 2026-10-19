<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ClientAccessService;
use App\Services\FamilyService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use InvalidArgumentException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendAccess')
                ->label('Отправить доступ')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                // У ребёнка без входа доступа нет: пароль ушёл бы представителю.
                ->visible(fn (User $record): bool => in_array($record->role, [UserRole::Client, UserRole::Trainer], true)
                    && ! $record->isManagedProfile())
                ->requiresConfirmation()
                // Кнопку не отключаем: бледная неактивная кнопка без пояснений выглядит
                // как поломка. Если отправлять некуда — модалка объясняет, что сделать.
                ->modalHeading(fn (User $record): string => $this->accessBlocker($record) === null
                    ? 'Отправить временный пароль'
                    : 'Некуда отправить доступ')
                ->modalDescription(function (User $record): string {
                    if (($blocker = $this->accessBlocker($record)) !== null) {
                        return $blocker;
                    }

                    $channels = array_filter([
                        filled($record->email) ? 'email: '.$record->email : null,
                        $record->hasTelegram() ? 'Telegram: '.$record->telegramDisplayAccount() : null,
                    ]);

                    $telegramNote = $record->hasTelegram()
                        ? ''
                        : ' Telegram пока не привязан — после входа пользователь может привязать его на сайте.';

                    return 'Система сгенерирует новый временный пароль, сохранит его и отправит: '.implode('; ', $channels).'.'.$telegramNote;
                })
                ->modalSubmitAction(fn (User $record) => $this->accessBlocker($record) === null ? null : false)
                ->modalCancelActionLabel(fn (User $record): string => $this->accessBlocker($record) === null ? 'Отмена' : 'Понятно')
                ->action(function (User $record, ClientAccessService $access): void {
                    if (($blocker = $this->accessBlocker($record)) !== null) {
                        Notification::make()
                            ->title('Не удалось отправить доступ')
                            ->body($blocker)
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $result = $access->sendTemporaryPassword($record);
                    } catch (InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Не удалось отправить доступ')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $channels = array_filter([
                        $result['email'] ? 'email' : null,
                        $result['telegram'] ? 'Telegram' : null,
                    ]);

                    if ($channels === []) {
                        Notification::make()
                            ->title('Пароль обновлён, но доставка не удалась')
                            ->body('Новый пароль: '.$result['password'].'. Проверьте настройки почты и Telegram на сервере.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Временный пароль отправлен')
                        ->body('Доставлено: '.implode(' и ', $channels).'. Пароль: '.$result['password'])
                        ->success()
                        ->send();
                }),
            $this->familyActions(),
            DeleteAction::make(),
        ];
    }

    /**
     * Семья клиента — для тех, кто просит по телефону. Правила те же, что у
     * клиента в кабинете (`FamilyService`): убрали человека или закрыли ему
     * абонемент — его будущие записи по нему отменяются с возвратом занятий.
     */
    private function familyActions(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('familyAdd')
                ->label('Добавить в семью')
                ->icon('heroicon-o-user-plus')
                ->modalHeading('Добавить клиента в семью')
                ->modalDescription('Если семьи ещё нет, этот клиент станет её главой. В семье до '.FamilyService::MAX_MEMBERS.' человек.')
                ->form([
                    Select::make('member_id')
                        ->label('Кого добавить')
                        ->options(fn (User $record) => User::query()
                            ->where('role', UserRole::Client)
                            ->whereNull('anonymized_at')
                            ->whereNull('guardian_id')
                            ->whereKeyNot($record->id)
                            ->whereDoesntHave('familyMembership')
                            ->orderBy('last_name')
                            ->get()
                            ->mapWithKeys(fn (User $u) => [$u->id => $u->fullName().' · '.($u->formattedPhone() ?? $u->phone)])
                            ->all())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data, User $record, FamilyService $family): void {
                    $this->runFamily(fn () => $family->addByAdmin($record, User::query()->findOrFail($data['member_id'])), 'Клиент добавлен в семью');
                }),
            Action::make('familyShares')
                ->label('Открыть абонемент семье')
                ->icon('heroicon-o-share')
                ->visible(fn (User $record, FamilyService $family): bool => ($family->groupOf($record)?->members()->count() ?? 0) > 1)
                ->modalDescription('Отмеченные участники смогут записываться по абонементу; лимит — сколько занятий за весь срок (пусто — без ограничений). Снятая галочка отменит их будущие записи по нему — занятия вернутся владельцу.')
                ->form(function (User $record, FamilyService $family): array {
                    $others = $family->groupOf($record)?->members()->with('user')->get()
                        ->reject(fn ($m) => $m->user_id === $record->id)
                        ->mapWithKeys(fn ($m) => [$m->user_id => $m->user->shortName()])
                        ->all() ?? [];

                    return [
                        Select::make('subscription_id')
                            ->label('Абонемент')
                            ->options($family->shareableSubscriptionsOf($record)
                                ->mapWithKeys(fn ($s) => [$s->id => $s->type->shortLabel().' · осталось '.$s->sessionsRemaining().' · до '.$s->ends_at->format('d.m.Y')])
                                ->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state) use ($others): void {
                                $shared = $state ? Subscription::query()->find($state)?->sharedWith()->get() ?? collect() : collect();
                                $set('user_ids', $shared->pluck('id')->map(fn ($id) => (string) $id)->all());

                                foreach (array_keys($others) as $id) {
                                    $set('limits.'.$id, $shared->firstWhere('id', $id)?->pivot->sessions_limit);
                                }
                            }),
                        CheckboxList::make('user_ids')
                            ->label('Кому открыт')
                            ->options($others)
                            ->visible(fn (Get $get) => filled($get('subscription_id'))),
                        ...collect($others)->map(fn (string $name, int $id) => TextInput::make('limits.'.$id)
                            ->label('Лимит занятий: '.$name)
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(FamilyService::MAX_SHARE_LIMIT)
                            ->placeholder('без ограничений')
                            ->visible(fn (Get $get) => filled($get('subscription_id')) && in_array((string) $id, $get('user_ids') ?? [], true)))
                            ->values()->all(),
                    ];
                })
                ->action(function (array $data, FamilyService $family): void {
                    $shares = collect($data['user_ids'] ?? [])
                        ->mapWithKeys(fn ($id) => [(int) $id => filled($data['limits'][$id] ?? null) ? (int) $data['limits'][$id] : null])
                        ->all();

                    $this->runFamily(fn () => $family->setSharesByAdmin(
                        Subscription::query()->findOrFail($data['subscription_id']),
                        $shares,
                    ), 'Доступ к абонементу сохранён');
                }),
            Action::make('familySeparate')
                ->label('Отделить профиль ребёнка')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->visible(fn (User $record): bool => $record->isManagedProfile())
                ->modalDescription('Ребёнок вырос и будет входить сам. Впишите его телефон (и почту, если есть) — представитель снимется, история посещений и абонементы останутся. Потом нажмите «Отправить доступ».')
                ->form([
                    TextInput::make('phone')->label('Телефон')->required()->tel(),
                    TextInput::make('email')->label('Email')->email(),
                ])
                ->action(function (array $data, User $record, FamilyService $family): void {
                    $this->runFamily(fn () => $family->separateChild($record, $data['phone'], $data['email'] ?? null), 'Профиль отделён');
                }),
            Action::make('familyRemove')
                ->label('Убрать из семьи')
                ->icon('heroicon-o-user-minus')
                ->color('danger')
                ->visible(fn (User $record, FamilyService $family): bool => $family->membershipOf($record) !== null)
                ->requiresConfirmation()
                ->modalDescription('Будущие записи клиента по абонементам семьи и записи других по его абонементам отменятся, занятия вернутся владельцам. Если клиент — глава, главой станет самый давний участник.')
                ->action(function (User $record, FamilyService $family): void {
                    $this->runFamily(fn () => $family->removeByAdmin($record), 'Клиент убран из семьи');
                }),
        ])
            ->label('Семья')
            ->icon('heroicon-o-users')
            ->button()
            // У группы нет своей записи в замыкании — берём запись страницы.
            ->visible(fn (): bool => $this->getRecord()->isClient());
    }

    private function runFamily(callable $action, string $success): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Не получилось')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
        $this->refreshFormData([]);
    }

    /**
     * Почему доступ сейчас нельзя отправить (null — можно).
     *
     * Отправка идёт по сохранённой карточке, поэтому отдельно ловим случай,
     * когда email уже вписан в форму, но «Сохранить» ещё не нажали.
     */
    private function accessBlocker(User $record): ?string
    {
        $formEmail = trim((string) ($this->data['email'] ?? ''));

        if ($formEmail !== '' && $formEmail !== (string) $record->email) {
            return 'Email в карточке изменён, но не сохранён. Нажмите «Сохранить» внизу страницы, затем снова «Отправить доступ».';
        }

        if (blank($record->email) && ! $record->hasTelegram()) {
            return 'В карточке не указан email, а Telegram не привязан. Впишите email, нажмите «Сохранить» внизу страницы, затем снова «Отправить доступ».';
        }

        return null;
    }
}
