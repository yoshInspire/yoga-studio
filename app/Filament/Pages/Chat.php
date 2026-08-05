<?php

namespace App\Filament\Pages;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ChatService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Переписка с клиентами со стороны студии — веб-версия.
 *
 * Бэкенд общий с приложением: тот же ChatService, те же таблицы. Отвечать
 * можно откуда удобнее, прочитанное отмечается в обоих местах одинаково.
 */
class Chat extends Page
{
    use WithFileUploads;

    protected static ?string $navigationLabel = 'Чат';

    protected static ?string $title = 'Переписка с клиентами';

    protected static ?int $navigationSort = 7;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected string $view = 'filament.pages.chat';

    /** Выбранный клиент. */
    public ?int $clientId = null;

    public string $search = '';

    public string $draft = '';

    public ?TemporaryUploadedFile $photo = null;

    public function mount(): void
    {
        // Открываем сразу того, кто ждёт ответа дольше всех остальных.
        $this->clientId = $this->conversations()->firstWhere('unread_count', '>', 0)?->user_id
            ?? $this->conversations()->first()?->user_id;

        $this->markRead();
    }

    /** @return Collection<int, Conversation> */
    public function conversations(): Collection
    {
        return app(ChatService::class)->conversationsForAdmin($this->search);
    }

    public function selectClient(int $id): void
    {
        $this->clientId = $id;
        $this->draft = '';
        $this->photo = null;
        $this->markRead();
    }

    /**
     * Лента выбранной переписки.
     *
     * Название не `messages()` намеренно: так называется зарезервированный
     * метод Livewire для своих текстов валидации, и подмена ломает validate().
     *
     * @return Collection<int, Message>
     */
    public function threadMessages(): Collection
    {
        $client = $this->client();

        if ($client === null) {
            return new Collection;
        }

        $service = app(ChatService::class);

        return $service->messages($service->forClient($client));
    }

    public function client(): ?User
    {
        if ($this->clientId === null) {
            return null;
        }

        return User::query()->find($this->clientId);
    }

    public function send(): void
    {
        $client = $this->client();

        if ($client === null) {
            return;
        }

        $this->validate([
            'draft' => ['nullable', 'string', 'max:4000'],
            'photo' => ['nullable', 'image', 'max:12288'],
        ], [], ['draft' => 'сообщение', 'photo' => 'фотография']);

        try {
            app(ChatService::class)->send(
                app(ChatService::class)->forClient($client),
                auth()->user(),
                $this->draft,
                // Livewire держит загруженный файл во временном хранилище;
                // сервис ждёт обычный UploadedFile, и это он и есть.
                $this->photo,
            );
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->draft = '';
        $this->photo = null;
    }

    /** Пометить прочитанным всё, что написал выбранный клиент. */
    public function markRead(): void
    {
        $client = $this->client();

        if ($client === null) {
            return;
        }

        $service = app(ChatService::class);
        $service->markRead($service->forClient($client), auth()->user());
    }

    /** Дёргается опросом из вёрстки: подтягивает новое и отмечает прочитанным. */
    public function refreshThread(): void
    {
        $this->markRead();
    }

    public function updatedSearch(): void
    {
        // Если выбранный клиент отфильтровался — переключаемся на первого видимого.
        $visible = $this->conversations();

        if ($visible->firstWhere('user_id', $this->clientId) === null) {
            $this->clientId = $visible->first()?->user_id;
            $this->markRead();
        }
    }

    public function getViewData(): array
    {
        return [
            'conversations' => $this->conversations(),
            'thread' => $this->threadMessages(),
            'selected' => $this->client(),
        ];
    }
}
