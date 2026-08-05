<x-filament-panels::page>
    {{-- Опрос раз в 10 секунд: этого хватает для переписки, а нагрузки почти нет. --}}
    <div class="chat" wire:poll.10s="refreshThread">
        {{-- Слева список переписок --}}
        <aside class="chat__list">
            <label class="chat__search">
                <input
                    type="search"
                    wire:model.live.debounce.350ms="search"
                    placeholder="Имя, телефон или почта"
                >
            </label>

            @forelse ($conversations as $conversation)
                @php
                    $isActive = $conversation->user_id === $clientId;
                    $unread = (int) ($conversation->unread_count ?? 0);
                @endphp

                <button
                    type="button"
                    wire:click="selectClient({{ $conversation->user_id }})"
                    class="chat__row @if ($isActive) chat__row--active @endif @if ($unread) chat__row--unread @endif"
                >
                    <span class="chat__avatar">{{ $conversation->user->initials() }}</span>

                    <span class="chat__rowBody">
                        <span class="chat__rowTop">
                            <span class="chat__name">{{ $conversation->user->fullName() }}</span>
                            @if ($conversation->last_message_at)
                                <span class="chat__when">{{ $conversation->last_message_at->format('d.m H:i') }}</span>
                            @endif
                        </span>

                        <span class="chat__rowBottom">
                            <span class="chat__preview">
                                @if ($conversation->latestMessage && $conversation->latestMessage->sender_id !== $conversation->user_id)
                                    Вы:
                                @endif
                                {{ $conversation->latestMessage?->preview(60) ?: 'Переписка пока пустая' }}
                            </span>
                            @if ($unread)
                                <span class="chat__badge">{{ $unread > 99 ? '99+' : $unread }}</span>
                            @endif
                        </span>
                    </span>
                </button>
            @empty
                <p class="chat__empty">
                    {{ $search === '' ? 'Переписок пока нет.' : 'По этому запросу никого не нашли.' }}
                </p>
            @endforelse
        </aside>

        {{-- Справа сама переписка --}}
        <section class="chat__thread">
            @if ($selected === null)
                <p class="chat__empty">Выберите клиента слева.</p>
            @else
                <header class="chat__head">
                    <span class="chat__avatar">{{ $selected->initials() }}</span>
                    <span>
                        <strong>{{ $selected->fullName() }}</strong>
                        @if ($selected->formattedPhone())
                            <small>{{ $selected->formattedPhone() }}</small>
                        @endif
                    </span>
                </header>

                <div class="chat__feed" id="chat-feed">
                    @php $lastDay = null; @endphp

                    @forelse ($thread as $message)
                        @php
                            $day = $message->created_at->toDateString();
                            $mine = $message->sender_id !== $selected->id;
                        @endphp

                        @if ($day !== $lastDay)
                            @php $lastDay = $day; @endphp
                            <div class="chat__day">{{ $message->created_at->translatedFormat('j F') }}</div>
                        @endif

                        <div class="chat__bubbleRow @if ($mine) chat__bubbleRow--mine @endif">
                            <div class="chat__bubble @if ($mine) chat__bubble--mine @endif">
                                @if ($message->attachmentExists())
                                    <a href="{{ route('chat.attachment', $message) }}" target="_blank" rel="noopener">
                                        <img
                                            src="{{ route('chat.attachment', $message) }}"
                                            alt="Фотография в сообщении"
                                            class="chat__photo"
                                        >
                                    </a>
                                @endif

                                @if (filled($message->body))
                                    <p>{{ $message->body }}</p>
                                @endif

                                <span class="chat__time">
                                    {{ $message->created_at->format('H:i') }}
                                    @if ($mine)
                                        {{ $message->read_at ? '✓✓' : '✓' }}
                                    @endif
                                </span>
                            </div>
                        </div>
                    @empty
                        <p class="chat__empty">Здесь пока пусто. Напишите первым — клиент увидит сообщение в приложении.</p>
                    @endforelse
                </div>

                <form class="chat__composer" wire:submit.prevent="send">
                    @if ($photo)
                        <div class="chat__attach">
                            <img src="{{ $photo->temporaryUrl() }}" alt="">
                            <span>Фото прикреплено</span>
                            <button type="button" wire:click="$set('photo', null)">Убрать</button>
                        </div>
                    @endif

                    <div class="chat__composerRow">
                        <label class="chat__file" title="Прикрепить фотографию">
                            <input type="file" wire:model="photo" accept="image/*" hidden>
                            <span>Фото</span>
                        </label>

                        <textarea
                            wire:model="draft"
                            rows="2"
                            placeholder="Ответить клиенту…"
                        ></textarea>

                        <button type="submit" class="chat__send" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="send">Отправить</span>
                            <span wire:loading wire:target="send">Отправляем…</span>
                        </button>
                    </div>

                    @error('photo') <p class="chat__error">{{ $message }}</p> @enderror
                    @error('draft') <p class="chat__error">{{ $message }}</p> @enderror
                </form>
            @endif
        </section>
    </div>

    @include('filament.pages.chat._styles')
</x-filament-panels::page>
