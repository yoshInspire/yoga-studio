<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\ClientMailingLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StudioMailingService
{
    public function __construct(
        private NotificationService $notifications,
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

            $this->notifications->notifyUser(
                $user,
                $message['heading'],
                $message['lines'],
                $message['subject'],
            );

            $this->markSent($user, ClientMailingLog::TYPE_DAILY_EVENING, $mailingKey);
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
                    ->whereDate('mailing_key', $mailingKey)
                    ->delete();
            }

            $this->notifications->notifyUser(
                $user,
                $message['heading'],
                $message['lines'],
                $message['subject'],
            );

            $this->markSent($user, ClientMailingLog::TYPE_WEEKLY_SCHEDULE, $mailingKey);
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
     * @return array{sent: int, mailing_key: string}
     */
    public function sendCustomAnnouncement(string $heading, string $body, bool $dryRun = false): array
    {
        $heading = trim($heading);
        $lines = $this->parseBodyLines($body);
        $mailingKey = now()->format('Y-m-d-His');
        $sent = 0;

        $this->eligibleClients()->each(function (User $user) use (
            $heading,
            $lines,
            $mailingKey,
            $dryRun,
            &$sent,
        ) {
            if ($dryRun) {
                $sent++;

                return;
            }

            $this->notifications->notifyUser(
                $user,
                $heading,
                $lines,
                $heading,
            );

            $this->markSent($user, ClientMailingLog::TYPE_CUSTOM, $mailingKey);
            $sent++;
        });

        return [
            'sent' => $sent,
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

                $this->notifications->notifyUser(
                    $user,
                    '🎂 С днём рождения!',
                    [$body],
                    'С днём рождения!',
                );

                $this->markSent($user, ClientMailingLog::TYPE_BIRTHDAY, $mailingKey);
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

        $lines[] = 'Если не можете прийти — пожалуйста, отмените или перенесите запись прямо сейчас через личный кабинет, чтобы место освободилось для других.';
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
                'Если захотите записаться — мы на связи: '.$this->scheduleUrl(),
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
                'Открыта запись на занятия с '.$weekStart->translatedFormat('l, j F')
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

    private function alreadySent(User $user, string $type, string $mailingKey): bool
    {
        return ClientMailingLog::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereDate('mailing_key', $mailingKey)
            ->exists();
    }

    private function markSent(User $user, string $type, string $mailingKey): void
    {
        ClientMailingLog::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'mailing_key' => $mailingKey,
            'sent_at' => now(),
        ]);
    }

    private function config(string $key): mixed
    {
        return config('studio.mailings.'.$key);
    }
}
