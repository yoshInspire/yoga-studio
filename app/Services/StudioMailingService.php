<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Jobs\SendClientMailing;
use App\Models\Booking;
use App\Models\ClientMailingLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Массовые рассылки клиентам студии.
 *
 * **Отправка идёт очередью, а не в запросе.** Раньше письма уходили прямо в
 * HTTP-запросе, и на семи десятках клиентов он не укладывался в таймаут
 * nginx: администратор видел «Ошибка при загрузке страницы», хотя рассылка
 * продолжала уходить, — и жал «Отправить» снова. Так 06.08.2026 сообщение
 * ушло людям по нескольку раз. Теперь нажатие только ставит задания
 * `SendClientMailing` (это доли секунды), а доставку ведёт воркер, которого
 * раз в минуту поднимает планировщик (`routes/console.php`).
 *
 * Защит от повторной отправки две, и они разного назначения:
 *  - `alreadySent()` перед постановкой — чтобы счётчики на экране были
 *    честными («уже отправлено, повтор не нужен»);
 *  - вставка строки журнала внутри самого задания — чтобы копия не ушла даже
 *    при гонке двух нажатий (см. `SendClientMailing::claim()`).
 */
class StudioMailingService
{
    /** Сколько помним, что рассылка с таким ключом уже запускалась. */
    private const RUN_MARK_TTL = 3600;

    public function __construct(
        private BirthdayGreetingService $birthdayGreetings,
    ) {}

    /**
     * @return array{with_bookings: int, without_bookings: int, skipped: int}
     */
    public function sendDailyReminders(?Carbon $on = null, bool $dryRun = false): array
    {
        if (! ($this->config('daily_reminder.enabled') ?? true)) {
            return ['with_bookings' => 0, 'without_bookings' => 0, 'skipped' => 0];
        }

        $on ??= now();
        $tomorrow = $on->copy()->addDay()->startOfDay();
        $tomorrowEnd = $tomorrow->copy()->endOfDay();
        $mailingKey = $tomorrow->toDateString();

        $bookingsByUser = Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->whereHas('classSession', fn ($q) => $q->whereBetween('starts_at', [$tomorrow, $tomorrowEnd]))
            ->with(['classSession' => fn ($q) => $q
                ->with('direction')
                ->withCount(['bookings as confirmed_count' => fn ($b) => $b->where('status', BookingStatus::Confirmed)]),
            ])
            ->get()
            ->groupBy('user_id');

        $counts = ['with_bookings' => 0, 'without_bookings' => 0, 'skipped' => 0];

        $this->eligibleClients()->each(function (User $user) use (
            $bookingsByUser,
            $tomorrow,
            $mailingKey,
            $dryRun,
            &$counts,
        ) {
            if ($this->alreadySent($user, ClientMailingLog::TYPE_DAILY_EVENING, $mailingKey)) {
                $counts['skipped']++;

                return;
            }

            /** @var Collection<int, Booking> $bookings */
            $bookings = $bookingsByUser->get($user->id, collect());

            if ($bookings->isNotEmpty()) {
                $message = $this->buildDailyWithBookingsMessage($user, $tomorrow, $bookings);
                $counts['with_bookings']++;
            } else {
                $message = $this->buildDailyWithoutBookingsMessage($user, $tomorrow);
                $counts['without_bookings']++;
            }

            if ($dryRun) {
                return;
            }

            $this->queueMailing($user, $message, ClientMailingLog::TYPE_DAILY_EVENING, $mailingKey, 'reminder');
        });

        return $counts;
    }

    /**
     * @return array{sent: int, skipped: int, from: string, to: string}
     */
    public function sendWeeklyScheduleAnnouncement(?Carbon $on = null, bool $dryRun = false, bool $force = false): array
    {
        if (! ($this->config('weekly_schedule.enabled') ?? true)) {
            return ['sent' => 0, 'skipped' => 0, 'from' => '', 'to' => ''];
        }

        $on ??= now();
        [$weekStart, $weekEnd] = $this->announcementWeekRange($on);
        $mailingKey = $weekStart->toDateString();
        $message = $this->buildWeeklyScheduleMessage($weekStart, $weekEnd);

        $sent = 0;
        $skipped = 0;

        $this->eligibleClients()->each(function (User $user) use (
            $mailingKey,
            $message,
            $dryRun,
            $force,
            &$sent,
            &$skipped,
        ) {
            if (! $force && $this->alreadySent($user, ClientMailingLog::TYPE_WEEKLY_SCHEDULE, $mailingKey)) {
                $skipped++;

                return;
            }

            if ($dryRun) {
                $sent++;

                return;
            }

            if ($force && $this->alreadySent($user, ClientMailingLog::TYPE_WEEKLY_SCHEDULE, $mailingKey)) {
                ClientMailingLog::query()
                    ->where('user_id', $user->id)
                    ->where('type', ClientMailingLog::TYPE_WEEKLY_SCHEDULE)
                    ->where('mailing_key', $mailingKey)
                    ->delete();
            }

            $this->queueMailing($user, $message, ClientMailingLog::TYPE_WEEKLY_SCHEDULE, $mailingKey, 'schedule');
            $sent++;
        });

        return [
            'sent' => $sent,
            'skipped' => $skipped,
            'from' => $weekStart->translatedFormat('l, j F'),
            'to' => $weekEnd->translatedFormat('l, j F'),
        ];
    }

    /**
     * Произвольное оповещение всем клиентам с принятой офертой.
     *
     * Ключ рассылки — дата плюс отпечаток текста. Из этого следует главное:
     * повторное нажатие «Отправить» с тем же сообщением никому не пошлёт
     * вторую копию (`skipped`), а другое сообщение в тот же день уйдёт как
     * обычно. Раньше ключ был меткой времени, то есть каждое нажатие
     * считалось новой рассылкой.
     *
     * @return array{sent: int, skipped: int, already_running: bool, mailing_key: string}
     */
    public function sendCustomAnnouncement(string $heading, string $body, bool $dryRun = false): array
    {
        $heading = trim($heading);
        $lines = $this->parseBodyLines($body);
        $mailingKey = $this->customMailingKey($heading, $lines);
        $message = ['heading' => $heading, 'subject' => $heading, 'lines' => $lines];

        $sent = 0;
        $skipped = 0;

        // Отметка о запуске живёт час и нужна для честного ответа экрану:
        // задания уже поставлены, но журнал заполнит воркер, и до этого
        // момента `alreadySent()` про них ещё не знает.
        if (! $dryRun && ! $this->markRunStarted($mailingKey)) {
            return [
                'sent' => 0,
                'skipped' => $this->eligibleClientsCount(),
                'already_running' => true,
                'mailing_key' => $mailingKey,
            ];
        }

        $this->eligibleClients()->each(function (User $user) use (
            $message,
            $mailingKey,
            $dryRun,
            &$sent,
            &$skipped,
        ) {
            if ($this->alreadySent($user, ClientMailingLog::TYPE_CUSTOM, $mailingKey)) {
                $skipped++;

                return;
            }

            if ($dryRun) {
                $sent++;

                return;
            }

            $this->queueMailing($user, $message, ClientMailingLog::TYPE_CUSTOM, $mailingKey, 'announcement');
            $sent++;
        });

        return [
            'sent' => $sent,
            'skipped' => $skipped,
            'already_running' => false,
            'mailing_key' => $mailingKey,
        ];
    }

    /**
     * @return array{sent: int, skipped: int}
     */
    public function sendBirthdayGreetings(?Carbon $on = null, bool $dryRun = false): array
    {
        if (! ($this->config('birthday.enabled') ?? true)) {
            return ['sent' => 0, 'skipped' => 0];
        }

        $on ??= now();
        $mailingKey = $on->toDateString();
        $counts = ['sent' => 0, 'skipped' => 0];

        $this->eligibleClients()
            ->birthdayOn($on)
            ->each(function (User $user) use ($mailingKey, $dryRun, &$counts) {
                if ($this->alreadySent($user, ClientMailingLog::TYPE_BIRTHDAY, $mailingKey)) {
                    $counts['skipped']++;

                    return;
                }

                $body = $this->birthdayGreetings->bodyForUser($user);

                if ($body === null) {
                    return;
                }

                if ($dryRun) {
                    $counts['sent']++;

                    return;
                }

                $this->queueMailing(
                    $user,
                    [
                        'heading' => '🎂 С днём рождения!',
                        'subject' => 'С днём рождения!',
                        'lines' => [$body],
                    ],
                    ClientMailingLog::TYPE_BIRTHDAY,
                    $mailingKey,
                    'birthday',
                );

                $counts['sent']++;
            });

        return $counts;
    }

    /**
     * @return list<string>
     */
    public function parseBodyLines(string $body): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split("/\r\n|\r|\n/", $body) ?: []),
            fn (string $line) => $line !== '',
        ));
    }

    public function eligibleClientsCount(): int
    {
        return $this->eligibleClients()->count();
    }

    /**
     * Кому анонс недели уже ушёл, а кому ещё нет.
     *
     * Нужно приложению, чтобы кнопка отправки не молчала: если анонс на эту
     * неделю уже уходил, повтор без `force` пропустит всех и снаружи будет
     * выглядеть поломкой.
     *
     * @return array{eligible: int, sent: int, pending: int, week_start: string, from: string, to: string}
     */
    public function weeklyAnnouncementProgress(?Carbon $on = null): array
    {
        [$weekStart, $weekEnd] = $this->announcementWeekRange($on);

        $eligible = $this->eligibleClients()->count();

        $sent = ClientMailingLog::query()
            ->where('type', ClientMailingLog::TYPE_WEEKLY_SCHEDULE)
            ->where('mailing_key', $weekStart->toDateString())
            ->whereIn('user_id', $this->eligibleClients()->reorder()->select('id'))
            ->count();

        return [
            'eligible' => $eligible,
            'sent' => $sent,
            'pending' => max(0, $eligible - $sent),
            'week_start' => $weekStart->toDateString(),
            'from' => $weekStart->translatedFormat('l, j F'),
            'to' => $weekEnd->translatedFormat('l, j F'),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function announcementWeekRange(?Carbon $on = null): array
    {
        $on ??= now();

        if ($on->isSunday()) {
            $weekStart = $on->copy()->addDay()->startOfDay();
        } else {
            $weekStart = $on->copy()->startOfDay();
            if (! $weekStart->isMonday()) {
                $weekStart = $weekStart->next(Carbon::MONDAY);
            }
        }

        return [$weekStart, $weekStart->copy()->addDays(6)->endOfDay()];
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array{heading: string, subject: string, lines: list<string>}
     */
    public function buildDailyWithBookingsMessage(User $user, Carbon $tomorrow, Collection $bookings): array
    {
        $lines = [
            'Здравствуйте!',
            'Вот что у вас запланировано на завтра, '.$tomorrow->translatedFormat('l').' '
                .$tomorrow->format('d.m').':',
        ];

        $awaitsGroupFill = false;

        foreach ($bookings as $booking) {
            $session = $booking->classSession;
            $lines[] = '📌 '.$session->title.' — в '.$session->formattedTime()
                .' (адрес: '.$this->studioAddress().')';

            if ($session->awaitsGroupFill($session->confirmed_count)) {
                $awaitsGroupFill = true;
                $lines[] = '⏳ Группа пока набирается — занятие подтвердим ближе к началу.';
            }
        }

        if ($awaitsGroupFill) {
            $lines[] = 'Если группа не наберётся, занятие отменится, а списанное занятие вернётся на ваш абонемент — мы сразу сообщим.';
        }

        $lines[] = 'Если не можете прийти — пожалуйста, отмените или перенесите бронирование прямо сейчас через личный кабинет, чтобы место освободилось для других.';
        $lines[] = '👉 '.$this->accountUrl();
        $lines[] = 'Хорошего вечера! 💙';

        return [
            'heading' => '🔔 Напоминание: ваши занятия на завтра',
            'subject' => 'Напоминание: ваши занятия на завтра',
            'lines' => $lines,
        ];
    }

    /**
     * @return array{heading: string, subject: string, lines: list<string>}
     */
    public function buildDailyWithoutBookingsMessage(User $user, Carbon $tomorrow): array
    {
        return [
            'heading' => 'Спокойной ночи!',
            'subject' => 'Завтра у вас нет занятий',
            'lines' => [
                'Завтра '.$tomorrow->translatedFormat('j F').' у вас нет активных занятий.',
                'Если захотите забронировать место — мы на связи: '.$this->scheduleUrl(),
            ],
        ];
    }

    /**
     * @return array{heading: string, subject: string, lines: list<string>}
     */
    public function buildWeeklyScheduleMessage(Carbon $weekStart, Carbon $weekEnd): array
    {
        return [
            'heading' => '🗓 Расписание на следующую неделю уже открыто!',
            'subject' => 'Расписание на следующую неделю открыто',
            'lines' => [
                'Здравствуйте! 👋',
                'Открыто бронирование занятий с '.$weekStart->translatedFormat('l, j F')
                    .' по '.$weekEnd->translatedFormat('l, j F').'.',
                '🔥 Успейте выбрать удобное время — свободные слоты разбирают быстро.',
                '👉 '.$this->scheduleUrl(),
                'До встречи на ковриках 🧘‍♀️🧘‍♂️!',
            ],
        ];
    }

    public function studioAddress(): string
    {
        return (string) ($this->config('studio_address')
            ?? 'Москва, ул. Академика Арцимовича, 13 (вход со двора)');
    }

    public function accountUrl(): string
    {
        return route('account', [], absolute: true);
    }

    public function scheduleUrl(): string
    {
        return route('schedule', [], absolute: true);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function eligibleClients()
    {
        return User::query()
            ->where('role', UserRole::Client)
            ->whereNotNull('offer_accepted_at')
            ->where(function ($query) {
                $query->whereNotNull('email')
                    ->orWhereNotNull('telegram_id');
            })
            ->orderBy('id');
    }

    /**
     * Поставить сообщение в очередь. Саму отправку и запись в журнал делает
     * `SendClientMailing` — там же защита от второй копии.
     *
     * @param  array{heading: string, subject?: string|null, lines: list<string>}  $message
     */
    private function queueMailing(
        User $user,
        array $message,
        string $logType,
        string $mailingKey,
        string $notificationType,
    ): void {
        SendClientMailing::dispatch(
            $user->id,
            $message['heading'],
            $message['lines'],
            $message['subject'] ?? null,
            $notificationType,
            $logType,
            $mailingKey,
        );
    }

    /**
     * Ключ произвольной рассылки: дата плюс отпечаток текста.
     *
     * Отпечаток — чтобы повтор того же сообщения узнавался; дата — чтобы одно
     * и то же объявление можно было повторить через неделю намеренно.
     *
     * @param  list<string>  $lines
     */
    private function customMailingKey(string $heading, array $lines): string
    {
        $fingerprint = substr(hash('sha256', $heading."\n".implode("\n", $lines)), 0, 16);

        return now()->toDateString().':'.$fingerprint;
    }

    /**
     * Отметить, что рассылка с таким ключом запущена. Вернёт false, если её
     * уже запускали — значит задания стоят в очереди и повтор не нужен.
     */
    private function markRunStarted(string $mailingKey): bool
    {
        return Cache::add('studio-mailing:'.$mailingKey, now()->toIso8601String(), self::RUN_MARK_TTL);
    }

    private function alreadySent(User $user, string $type, string $mailingKey): bool
    {
        return ClientMailingLog::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->where('mailing_key', $mailingKey)
            ->exists();
    }

    private function config(string $key): mixed
    {
        return config('studio.mailings.'.$key);
    }
}
