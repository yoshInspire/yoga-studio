<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Exports\BookingsAnalyticsExport;
use App\Exports\ClientStatsExport;
use App\Exports\SubscriptionsWorkbookExport;
use App\Exports\VisitsExport;
use App\Exports\WeeklyBookingsExport;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Выгрузки Excel из приложения (ADMIN_PLAN_2.md, фаза J).
 *
 * Пять тех же отчётов, что на странице «Отчёты» в веб-админке, теми же
 * классами `App\Exports\*`: числа в файле, скачанном с телефона и из браузера,
 * обязаны совпадать до строки.
 *
 * **Файл отдаётся прямо в ответе, а не по временной подписанной ссылке.**
 * В плане была ссылка — из опасения, что скачиванию в приложении негде взять
 * заголовок авторизации. Оказалось, есть: `File.downloadFileAsync` в
 * expo-file-system 19 принимает `headers`. Подписанная ссылка означала бы
 * общедоступный (пусть и на десять минут) адрес, за которым лежат телефоны и
 * посещения клиентов, плюс временный файл на диске и его уборку. Ничего этого
 * не нужно.
 *
 * Имя файла приложение берёт из `Content-Disposition` — своей копии правил
 * именования у него нет.
 */
class ReportController extends Controller
{
    public function __construct(
        private BookingService $bookings,
    ) {}

    /**
     * Каталог отчётов и список клиентов для выбора.
     *
     * Клиенты приезжают целиком, как и в форме Filament: их меньше сотни, а
     * поиск по загруженному списку на телефоне работает без запроса к серверу.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'reports' => [
                [
                    'key' => 'subscriptions',
                    'title' => 'Абонементы',
                    'description' => 'Три листа: групповые, индивидуальные и мероприятия вне абонемента. '
                        .'Использовано, остаток и даты посещений — на момент выгрузки, будущие записи не в счёт.',
                    'params' => [],
                ],
                [
                    'key' => 'client-stats',
                    'title' => 'Статистика по клиентам',
                    'description' => 'Дата регистрации и посещения по месяцам. Можно выбрать одного клиента.',
                    'params' => ['client'],
                ],
                [
                    'key' => 'weekly-bookings',
                    'title' => 'Записи на неделю',
                    'description' => 'Понедельник — воскресенье в столбцах: все открытые занятия и записавшиеся по ФИО.',
                    'params' => ['week'],
                ],
                [
                    'key' => 'bookings-analytics',
                    'title' => 'Аналитика записей',
                    'description' => 'Занятия с датой, временем, списком записавшихся и количеством мест.',
                    'params' => ['period'],
                ],
                [
                    'key' => 'visits',
                    'title' => 'Посещения',
                    'description' => 'ФИО, телефон и даты завершённых посещений.',
                    'params' => ['period', 'client'],
                ],
            ],
            'clients' => User::query()
                ->where('role', UserRole::Client)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->fullName()])
                ->all(),
        ]);
    }

    /** Собрать и отдать файл. Ключ отчёта — из списка выше. */
    public function download(Request $request, string $key): BinaryFileResponse
    {
        // Даты приходят из календаря, а клиент — из списка, так что промахнуться
        // с телефона нечем. Проверка здесь, чтобы кривой запрос отвечал 422,
        // а не падал внутри Carbon::parse пятисоткой.
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'week_date' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $today = now()->format('Y-m-d');

        return match ($key) {
            'subscriptions' => Excel::download(
                new SubscriptionsWorkbookExport,
                'abonementy-'.$today.'.xlsx',
            ),
            'client-stats' => Excel::download(
                new ClientStatsExport($this->clientId($request)),
                'statistika-klientov-'.$today.'.xlsx',
            ),
            'weekly-bookings' => $this->weeklyBookings($request),
            'bookings-analytics' => Excel::download(
                new BookingsAnalyticsExport(
                    $this->date($request->query('from')),
                    $this->date($request->query('to'), endOfDay: true),
                ),
                'zapisi-'.$today.'.xlsx',
            ),
            'visits' => Excel::download(
                new VisitsExport(
                    $this->date($request->query('from')),
                    $this->date($request->query('to'), endOfDay: true),
                    $this->clientId($request),
                ),
                'poseshcheniya-'.$today.'.xlsx',
            ),
            default => abort(404, 'Такого отчёта нет.'),
        };
    }

    private function weeklyBookings(Request $request): BinaryFileResponse
    {
        // Понедельник считает сервис — тот же, что рисует расписание.
        $weekStart = $this->bookings->weekStart(
            filled($request->query('week_date'))
                ? Carbon::parse((string) $request->query('week_date'))->toDateString()
                : null,
        );

        return Excel::download(
            new WeeklyBookingsExport($weekStart),
            'zapisi-nedelya-'.$weekStart->format('Y-m-d').'.xlsx',
        );
    }

    /** Клиент выбран в списке — либо не выбран, и тогда отчёт по всем. */
    private function clientId(Request $request): ?int
    {
        $value = $request->query('user_id');

        return filled($value) ? (int) $value : null;
    }

    private function date(mixed $value, bool $endOfDay = false): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }

        $date = Carbon::parse((string) $value);

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }
}
