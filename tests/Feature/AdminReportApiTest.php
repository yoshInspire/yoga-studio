<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exports\BookingsAnalyticsExport;
use App\Exports\ClientStatsExport;
use App\Exports\SubscriptionsWorkbookExport;
use App\Exports\VisitsExport;
use App\Exports\WeeklyBookingsExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Отчёты Excel из приложения (ADMIN_PLAN_2.md, фаза J).
 *
 * Содержимое выгрузок проверяют отдельные наборы (`WeeklyBookingsReportTest`,
 * `SubscriptionReportTest` и т.д.) — здесь только то, что добавляет API:
 * права, разбор параметров и имя файла, по которому приложение назовёт
 * скачанное.
 */
class AdminReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Среда: понедельник этой недели — 8 июня.
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00', config('app.timezone')));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_client_cannot_reach_reports(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Client]), 'sanctum')
            ->getJson('/api/v1/admin/reports')
            ->assertForbidden();
    }

    public function test_trainer_cannot_download_visits(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Trainer]), 'sanctum')
            ->get('/api/v1/admin/reports/visits')
            ->assertForbidden();
    }

    public function test_index_lists_five_reports_with_their_parameters(): void
    {
        $payload = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/reports')
            ->assertOk()
            ->json();

        $this->assertCount(5, $payload['reports']);

        $params = collect($payload['reports'])->pluck('params', 'key')->all();
        $this->assertSame([], $params['subscriptions']);
        $this->assertSame(['client'], $params['client-stats']);
        $this->assertSame(['week'], $params['weekly-bookings']);
        $this->assertSame(['period'], $params['bookings-analytics']);
        $this->assertSame(['period', 'client'], $params['visits']);
    }

    public function test_index_returns_clients_for_the_picker(): void
    {
        User::factory()->create([
            'role' => UserRole::Client,
            'first_name' => 'Анна',
            'last_name' => 'Иванова',
            'patronymic' => null,
        ]);
        // Тренер в списке клиентов делать нечего.
        User::factory()->create(['role' => UserRole::Trainer]);

        $payload = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/reports')
            ->assertOk()
            ->json();

        $this->assertCount(1, $payload['clients']);
        $this->assertSame('Иванова Анна', $payload['clients'][0]['name']);
    }

    public function test_unknown_report_is_not_found(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/v1/admin/reports/vydumannyj')
            ->assertNotFound();
    }

    public function test_subscriptions_workbook_is_downloaded_with_dated_name(): void
    {
        Excel::fake();

        $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/v1/admin/reports/subscriptions')
            ->assertOk();

        Excel::assertDownloaded('abonementy-2026-06-10.xlsx', function (SubscriptionsWorkbookExport $export) {
            return count($export->sheets()) === 3;
        });
    }

    public function test_client_stats_can_be_limited_to_one_client(): void
    {
        Excel::fake();

        $client = User::factory()->create(['role' => UserRole::Client]);

        $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/v1/admin/reports/client-stats?user_id='.$client->id)
            ->assertOk();

        Excel::assertDownloaded('statistika-klientov-2026-06-10.xlsx');
    }

    public function test_weekly_bookings_name_carries_the_monday_of_the_chosen_day(): void
    {
        Excel::fake();

        // Пятница 12 июня — файл должен назваться понедельником 8-го.
        $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/v1/admin/reports/weekly-bookings?week_date=2026-06-12')
            ->assertOk();

        Excel::assertDownloaded('zapisi-nedelya-2026-06-08.xlsx', function (WeeklyBookingsExport $export) {
            return true;
        });
    }

    public function test_weekly_bookings_without_a_date_takes_the_current_week(): void
    {
        Excel::fake();

        $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/v1/admin/reports/weekly-bookings')
            ->assertOk();

        Excel::assertDownloaded('zapisi-nedelya-2026-06-08.xlsx');
    }

    public function test_bookings_analytics_accepts_a_period(): void
    {
        Excel::fake();

        $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/v1/admin/reports/bookings-analytics?from=2026-06-01&to=2026-06-30')
            ->assertOk();

        Excel::assertDownloaded('zapisi-2026-06-10.xlsx', function (BookingsAnalyticsExport $export) {
            return true;
        });
    }

    public function test_visits_accepts_period_and_client_together(): void
    {
        Excel::fake();

        $client = User::factory()->create(['role' => UserRole::Client]);

        $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/v1/admin/reports/visits?from=2026-06-01&to=2026-06-30&user_id='.$client->id)
            ->assertOk();

        Excel::assertDownloaded('poseshcheniya-2026-06-10.xlsx', function (VisitsExport $export) {
            return true;
        });
    }

    public function test_reports_without_parameters_still_work(): void
    {
        Excel::fake();

        $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/v1/admin/reports/visits')
            ->assertOk();

        Excel::assertDownloaded('poseshcheniya-2026-06-10.xlsx');
    }

    public function test_broken_date_answers_422_instead_of_falling_over(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/reports/visits?from=позавчера')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);
    }

    public function test_client_stats_export_really_produces_a_file(): void
    {
        // Один прогон без Excel::fake(): проверяем, что выгрузка собирается,
        // а не только что контроллер её попросил.
        User::factory()->create(['role' => UserRole::Client]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/v1/admin/reports/client-stats')
            ->assertOk();

        $this->assertStringContainsString(
            'statistika-klientov-2026-06-10.xlsx',
            (string) $response->headers->get('content-disposition'),
        );
        $this->assertNotSame('', $response->streamedContent());
    }
}
