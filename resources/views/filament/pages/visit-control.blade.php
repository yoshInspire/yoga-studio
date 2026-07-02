<x-filament-panels::page>
    @php
        $day = $day ?? [];
        $stats = $day['stats'] ?? ['sessions' => 0, 'bookings' => 0, 'attended' => 0, 'no_show' => 0, 'pending' => 0];
        $sessions = $day['sessions'] ?? [];
    @endphp

    <div class="vc" wire:key="visit-control-{{ $selectedDate }}">
        {{-- Навигация по дням: стрелки + выбор даты --}}
        <div class="vc-date fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="vc-date__row">
                <button type="button" class="vc-date__arrow" wire:click="goToDate('{{ $prevDate }}')" aria-label="Предыдущий день">
                    ‹
                </button>

                <label class="vc-date__picker">
                    <span class="vc-date__weekday">
                        {{ $day['weekday'] ?? '' }}
                        @if ($isToday)
                            <span class="vc-date__tag">сегодня</span>
                        @endif
                    </span>
                    <span class="vc-date__label">{{ $day['date_label'] ?? '' }}</span>
                    <input
                        type="date"
                        class="vc-date__input"
                        wire:model.live="selectedDate"
                        aria-label="Выбрать дату"
                    />
                </label>

                <button type="button" class="vc-date__arrow" wire:click="goToDate('{{ $nextDate }}')" aria-label="Следующий день">
                    ›
                </button>
            </div>

            @if (! $isToday)
                <button type="button" class="vc-date__today" wire:click="goToToday">
                    Перейти к сегодня
                </button>
            @endif
        </div>

        {{-- Сводка --}}
        <div class="vc-stats">
            <div class="vc-stat">
                <span class="vc-stat__num">{{ $stats['sessions'] }}</span>
                <span class="vc-stat__lbl">занятий</span>
            </div>
            <div class="vc-stat">
                <span class="vc-stat__num">{{ $stats['bookings'] }}</span>
                <span class="vc-stat__lbl">записей</span>
            </div>
            <div class="vc-stat vc-stat--ok">
                <span class="vc-stat__num">{{ $stats['attended'] }}</span>
                <span class="vc-stat__lbl">пришли</span>
            </div>
            <div class="vc-stat vc-stat--warn">
                <span class="vc-stat__num">{{ $stats['pending'] }}</span>
                <span class="vc-stat__lbl">ждут</span>
            </div>
            @if ($stats['no_show'] > 0)
                <div class="vc-stat vc-stat--bad" style="grid-column: 1 / -1">
                    <span class="vc-stat__num">{{ $stats['no_show'] }}</span>
                    <span class="vc-stat__lbl">не пришли</span>
                </div>
            @endif
        </div>

        @if ($sessions === [])
            <div class="vc-empty fi-section rounded-xl bg-white p-8 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-base font-medium text-gray-950 dark:text-white">На этот день занятий нет</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Выберите другую дату или добавьте занятие в расписании.</p>
            </div>
        @endif

        @foreach ($sessions as $session)
            <section class="vc-session fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10{{ $session['is_cancelled'] ? ' vc-session--cancelled' : '' }}">
                <header class="vc-session__head">
                    <div class="vc-session__time">{{ $session['time_range'] }}</div>
                    <div class="vc-session__meta">
                        <span class="vc-badge vc-badge--type">{{ $session['type_label'] }}</span>
                        @if ($session['is_cancelled'])
                            <span class="vc-badge vc-badge--cancelled">Отменено</span>
                        @else
                            <span class="vc-badge vc-badge--seats">{{ $session['taken'] }} / {{ $session['capacity'] }}</span>
                        @endif
                    </div>
                    <h2 class="vc-session__title">{{ $session['title'] }}</h2>
                    @if ($session['trainer'])
                        <p class="vc-session__trainer">Тренер: {{ $session['trainer'] }}</p>
                    @endif
                    @if (! $session['is_cancelled'] && $session['taken'] < $session['capacity'])
                        <a href="{{ $createBookingBaseUrl }}?class_session_id={{ $session['id'] }}" class="vc-session__book">
                            ＋ Записать клиента
                        </a>
                    @endif
                </header>

                @if ($session['attendees'] === [])
                    <p class="vc-session__empty">Пока никто не записан</p>
                @else
                    <ul class="vc-attendees">
                        @foreach ($session['attendees'] as $attendee)
                            <li class="vc-attendee" wire:key="booking-{{ $attendee['booking_id'] }}">
                                <div class="vc-attendee__top">
                                    <div class="vc-attendee__name">{{ $attendee['name'] }}</div>
                                    @if ($attendee['phone_href'])
                                        <a href="{{ $attendee['phone_href'] }}" class="vc-attendee__phone">{{ $attendee['phone'] }}</a>
                                    @endif
                                </div>

                                <div class="vc-attendee__badges">
                                    <span class="vc-badge vc-badge--{{ $attendee['attendance_color'] }}">{{ $attendee['attendance_label'] }}</span>
                                    <span class="vc-badge vc-badge--{{ $attendee['charge_color'] }}">{{ $attendee['charge_label'] }}</span>
                                    @if ($attendee['sessions_remaining'] !== null)
                                        <span class="vc-badge vc-badge--sub">
                                            {{ $attendee['subscription_label'] }}
                                            @if ($attendee['subscription_starts_at'])
                                                · с {{ $attendee['subscription_starts_at'] }}
                                            @endif
                                            · остаток {{ $attendee['sessions_remaining'] }}/{{ $attendee['sessions_total'] }}
                                        </span>
                                    @endif
                                </div>

                                @if ($attendee['health_note'])
                                    <p class="vc-attendee__health">⚕ {{ $attendee['health_note'] }}</p>
                                @endif

                                @if ($attendee['attendance_pending'] && ! $session['is_cancelled'])
                                    <div class="vc-attendee__actions">
                                        <button type="button"
                                            class="vc-btn vc-btn--ok"
                                            wire:click="markAttended({{ $attendee['booking_id'] }})"
                                            wire:loading.attr="disabled"
                                            wire:target="markAttended({{ $attendee['booking_id'] }})">
                                            Был(а)
                                        </button>
                                        <button type="button"
                                            class="vc-btn vc-btn--no"
                                            wire:click="markNoShow({{ $attendee['booking_id'] }})"
                                            wire:confirm="Отметить неявку и вернуть занятие на абонемент?"
                                            wire:loading.attr="disabled"
                                            wire:target="markNoShow({{ $attendee['booking_id'] }})">
                                            Не пришёл(ла)
                                        </button>
                                    </div>
                                    <button type="button"
                                        class="vc-attendee__cancel"
                                        wire:click="cancelBooking({{ $attendee['booking_id'] }})"
                                        wire:confirm="Отменить запись и вернуть занятие на абонемент?">
                                        Отменить запись
                                    </button>
                                @elseif ($attendee['attendance'] === 'no_show')
                                    <p class="vc-attendee__note">Занятие возвращено на абонемент</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>

    <style>
        .vc { display: flex; flex-direction: column; gap: 12px; max-width: 640px; }
        .vc-date { padding: 12px 14px; }
        .vc-date__row { display: flex; align-items: center; gap: 10px; }
        .vc-date__arrow {
            flex: 0 0 44px; height: 44px; border-radius: 12px; border: 1px solid rgba(0,0,0,.08);
            background: #f9fafb; font-size: 1.4rem; line-height: 1; color: #374151; cursor: pointer;
        }
        .dark .vc-date__arrow { background: #1f2937; border-color: rgba(255,255,255,.1); color: #e5e7eb; }
        .vc-date__picker {
            position: relative; flex: 1; min-width: 0; display: flex; flex-direction: column;
            align-items: center; justify-content: center; text-align: center; cursor: pointer;
            padding: 4px 8px; border-radius: 10px;
        }
        .vc-date__picker:active { background: rgba(0,0,0,.03); }
        .vc-date__input {
            position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer;
        }
        .vc-date__weekday {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            font-size: .8rem; color: #6b7280; margin: 0; text-transform: capitalize;
        }
        .dark .vc-date__weekday { color: #9ca3af; }
        .vc-date__tag {
            padding: 2px 8px; border-radius: 999px; background: #d1fae5; color: #065f46;
            font-size: .68rem; font-weight: 700; text-transform: lowercase;
        }
        .vc-date__label { font-size: 1.05rem; font-weight: 700; margin: 2px 0 0; color: #111827; line-height: 1.25; }
        .dark .vc-date__label { color: #f9fafb; }
        .vc-date__today {
            display: block; width: 100%; margin-top: 10px; padding: 8px; border: none; border-top: 1px solid rgba(0,0,0,.06);
            background: none; font-size: .82rem; font-weight: 600; color: rgb(var(--primary-600)); cursor: pointer; text-align: center;
        }
        .dark .vc-date__today { border-color: rgba(255,255,255,.08); }
        .vc-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
        .vc-stat {
            min-width: 0; padding: 10px 8px; border-radius: 12px;
            background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.04); border: 1px solid rgba(0,0,0,.06); text-align: center;
        }
        .dark .vc-stat { background: #111827; border-color: rgba(255,255,255,.08); }
        .vc-stat__num { display: block; font-size: 1.35rem; font-weight: 700; color: #111827; line-height: 1.2; }
        .dark .vc-stat__num { color: #f9fafb; }
        .vc-stat__lbl { display: block; font-size: .72rem; color: #6b7280; margin-top: 2px; }
        .vc-stat--ok .vc-stat__num { color: #059669; }
        .vc-stat--warn .vc-stat__num { color: #d97706; }
        .vc-stat--bad .vc-stat__num { color: #dc2626; }
        .vc-session { overflow: hidden; }
        .vc-session--cancelled { opacity: .75; }
        .vc-session__head { padding: 14px 16px 10px; border-bottom: 1px solid rgba(0,0,0,.06); }
        .dark .vc-session__head { border-color: rgba(255,255,255,.08); }
        .vc-session__time { font-size: 1.1rem; font-weight: 700; color: #111827; }
        .dark .vc-session__time { color: #f9fafb; }
        .vc-session__meta { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
        .vc-session__title { margin: 8px 0 0; font-size: .95rem; font-weight: 600; color: #374151; line-height: 1.35; }
        .dark .vc-session__title { color: #e5e7eb; }
        .vc-session__trainer { margin: 4px 0 0; font-size: .8rem; color: #6b7280; }
        .vc-session__book {
            display: inline-flex; margin-top: 10px; padding: 8px 14px; border-radius: 8px;
            background: rgb(var(--primary-600)); color: #fff; font-size: .85rem; font-weight: 600; text-decoration: none;
        }
        .vc-session__empty { padding: 16px; margin: 0; font-size: .9rem; color: #6b7280; text-align: center; }
        .vc-attendees { list-style: none; margin: 0; padding: 0; }
        .vc-attendee { padding: 14px 16px; border-bottom: 1px solid rgba(0,0,0,.05); }
        .dark .vc-attendee { border-color: rgba(255,255,255,.06); }
        .vc-attendee:last-child { border-bottom: none; }
        .vc-attendee__top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .vc-attendee__name { font-size: 1rem; font-weight: 600; color: #111827; line-height: 1.3; }
        .dark .vc-attendee__name { color: #f9fafb; }
        .vc-attendee__phone { flex-shrink: 0; font-size: .85rem; font-weight: 600; color: rgb(var(--primary-600)); text-decoration: none; }
        .vc-attendee__badges { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .vc-badge {
            display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: .72rem; font-weight: 600; line-height: 1.3;
            background: #f3f4f6; color: #4b5563;
        }
        .vc-badge--success { background: #d1fae5; color: #065f46; }
        .vc-badge--warning { background: #fef3c7; color: #92400e; }
        .vc-badge--danger { background: #fee2e2; color: #991b1b; }
        .vc-badge--info { background: #dbeafe; color: #1e40af; }
        .vc-badge--gray { background: #f3f4f6; color: #6b7280; }
        .vc-badge--type { background: #ede9fe; color: #5b21b6; }
        .vc-badge--seats { background: #e0f2fe; color: #0369a1; }
        .vc-badge--cancelled { background: #fee2e2; color: #991b1b; }
        .vc-badge--sub { background: #f9fafb; color: #374151; border: 1px solid rgba(0,0,0,.06); }
        .vc-attendee__health {
            margin: 8px 0 0; padding: 8px 10px; border-radius: 8px; background: #fff7ed;
            font-size: .8rem; color: #9a3412; line-height: 1.35;
        }
        .vc-attendee__actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
        .vc-btn {
            flex: 1 1 calc(50% - 3px); min-width: 0; min-height: 0;
            padding: 7px 10px; border-radius: 8px; border: 1px solid transparent;
            font-size: .78rem; font-weight: 600; cursor: pointer; line-height: 1.25;
            text-align: center;
        }
        .vc-btn:disabled { opacity: .6; cursor: wait; }
        .vc-btn--ok { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .vc-btn--no { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
        .vc-attendee__cancel {
            display: block; width: 100%; margin-top: 8px; padding: 6px; border: none; background: none;
            font-size: .78rem; color: #9ca3af; text-decoration: underline; cursor: pointer; text-align: center;
        }
        .vc-attendee__note { margin: 8px 0 0; font-size: .78rem; color: #6b7280; }
        @media (min-width: 768px) {
            .vc { max-width: 720px; }
        }
    </style>
</x-filament-panels::page>
