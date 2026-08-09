<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\BirthdayGreetingService;
use App\Services\StudioMailingService;
use App\Services\WelcomeMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Рассылки клиентам из приложения (ADMIN_PLAN_2.md, фаза I).
 *
 * Обёртка над готовыми `StudioMailingService`, `WelcomeMessageService` и
 * `BirthdayGreetingService` — теми же, что работают на странице Filament и в
 * командах расписания. Своей логики отправки здесь нет намеренно: тексты,
 * список получателей и журнал `client_mailing_logs` обязаны совпадать
 * с сайтом.
 *
 * **Dry-run в приложение не переносится.** В вебе он есть, но администратор
 * студии — один человек, и лишняя «проверочная» кнопка рядом с настоящей
 * отправкой только увеличивает шанс промахнуться. Вместо неё экран заранее
 * знает число получателей и показывает его в подтверждении.
 *
 * **Ежедневное напоминание отправить руками нельзя** — и в вебе тоже: оно
 * уходит по расписанию в 20:00 (`studio:daily-booking-reminders`). Блок на
 * экране справочный.
 */
class MailingController extends Controller
{
    public function __construct(
        private StudioMailingService $mailings,
        private WelcomeMessageService $welcome,
        private BirthdayGreetingService $birthdays,
    ) {}

    /** Тексты, справка по автозапускам и число получателей. */
    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /** Сохранить памятку «К вашему визиту». */
    public function updateWelcome(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ], [
            'body.required' => 'Текст памятки не может быть пустым.',
            'body.max' => 'Текст памятки длиннее 4000 знаков.',
        ]);

        $this->welcome->saveBody($data['body']);

        return response()->json([
            'body' => $this->welcome->body(),
            'message' => 'Текст памятки сохранён. Новые клиенты получат его при первом бронировании.',
        ]);
    }

    /**
     * Сохранить варианты поздравления с днём рождения.
     *
     * Варианты идут по кругу: клиент получает следующий по счёту в свой
     * следующий день рождения (`BirthdayGreetingService::bodyForUser`).
     * Поэтому список пересобирается целиком, а не построчно.
     */
    public function updateBirthday(Request $request): JsonResponse
    {
        $data = $request->validate([
            'greetings' => ['required', 'array', 'min:1', 'max:20'],
            'greetings.*' => ['required', 'string', 'max:2000'],
        ], [
            'greetings.required' => 'Нужен хотя бы один вариант поздравления.',
            'greetings.min' => 'Нужен хотя бы один вариант поздравления.',
            'greetings.*.required' => 'Заполните текст варианта.',
            'greetings.*.max' => 'Вариант длиннее 2000 знаков.',
        ]);

        try {
            $this->birthdays->syncBodies(array_values($data['greetings']));
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'greetings' => $this->birthdays->orderedBodies(),
            'message' => 'Тексты поздравлений сохранены.',
        ]);
    }

    /**
     * Разослать анонс открытия записи на неделю.
     *
     * `force` нужен, когда рассылка на эту неделю уже уходила: без него
     * сервис пропустит тех, кто её получил, и нажатие ничего не сделает.
     * Экран знает об этом из `weekly.sent`/`weekly.pending` и подставляет
     * флаг сам — отдельной кнопки «отправить повторно» в приложении нет.
     */
    public function sendWeekly(Request $request): JsonResponse
    {
        $force = $request->boolean('force');

        $result = $this->mailings->sendWeeklyScheduleAnnouncement(force: $force);

        return response()->json([
            'sent' => $result['sent'],
            'skipped' => $result['skipped'],
            'from' => $result['from'],
            'to' => $result['to'],
            'message' => $result['sent'] === 0
                ? 'Никому не отправлено: все получатели уже получили анонс на эту неделю.'
                : 'Анонс отправлен: '.$result['sent'].'.',
            'state' => $this->payload(),
        ]);
    }

    /** Произвольное оповещение всем клиентам с принятой офертой. */
    public function sendCustom(Request $request): JsonResponse
    {
        $data = $request->validate([
            'heading' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:5000'],
        ], [
            'heading.required' => 'Укажите тему сообщения.',
            'body.required' => 'Напишите текст сообщения.',
        ]);

        $result = $this->mailings->sendCustomAnnouncement(
            heading: $data['heading'],
            body: $data['body'],
        );

        return response()->json([
            'sent' => $result['sent'],
            'message' => 'Оповещение отправлено: '.$result['sent'].'.',
        ]);
    }

    /**
     * Состояние всех блоков экрана.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $progress = $this->mailings->weeklyAnnouncementProgress();

        return [
            'recipients' => $progress['eligible'],
            'welcome' => [
                'body' => $this->welcome->body(),
                // Текст по умолчанию отдаём сразу: кнопка «вернуть текст»
                // просто подставляет его в поле, как и в вебе, — своего
                // маршрута для этого не нужно.
                'default_body' => $this->welcome->defaultBody(),
                'max_length' => 4000,
            ],
            'birthday' => [
                'greetings' => $this->birthdays->orderedBodies(),
                'max_length' => 2000,
                'time' => (string) (config('studio.mailings.birthday.time') ?? '09:00'),
            ],
            'daily' => [
                'time' => (string) (config('studio.mailings.daily_reminder.time') ?? '20:00'),
                'enabled' => (bool) (config('studio.mailings.daily_reminder.enabled') ?? true),
            ],
            'weekly' => [
                'time' => (string) (config('studio.mailings.weekly_schedule.time') ?? '14:00'),
                'enabled' => (bool) (config('studio.mailings.weekly_schedule.enabled') ?? true),
                'from' => $progress['from'],
                'to' => $progress['to'],
                'sent' => $progress['sent'],
                'pending' => $progress['pending'],
            ],
        ];
    }
}
