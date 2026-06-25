<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Exports\WeeklyBookingsExport;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WeeklyBookingsReportTest extends TestCase
{
    use RefreshDatabase;

    private ReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = app(ReportService::class);
    }

    public function test_weekly_bookings_grid_groups_attendees_by_day_and_session(): void
    {
        Carbon::setTestNow('2026-06-24 08:00:00');
        $weekStart = Carbon::parse('2026-06-22')->startOfDay();

        $trainer = User::create([
            'first_name' => 'Ирина',
            'last_name' => 'Коленцева',
            'phone' => '+79990000010',
            'role' => UserRole::Trainer,
            'password' => 'secret123',
        ]);

        $clientA = User::create([
            'first_name' => 'Анна',
            'last_name' => 'Иванова',
            'patronymic' => 'Сергеевна',
            'phone' => '+79990000011',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $clientB = User::create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'patronymic' => 'Иванович',
            'phone' => '+79990000012',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $tuesdaySession = ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => $weekStart->copy()->addDay()->setTime(10, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
            'trainer_id' => $trainer->id,
        ]);

        $wednesdaySession = ClassSession::create([
            'topic' => 'Индивидуальное',
            'starts_at' => $weekStart->copy()->addDays(2)->setTime(18, 0),
            'type' => SubscriptionType::Individual,
            'capacity' => 1,
            'status' => ClassSessionStatus::Scheduled,
            'trainer_id' => $trainer->id,
        ]);

        Booking::create([
            'user_id' => $clientA->id,
            'class_session_id' => $tuesdaySession->id,
            'status' => BookingStatus::Confirmed,
        ]);

        Booking::create([
            'user_id' => $clientB->id,
            'class_session_id' => $tuesdaySession->id,
            'status' => BookingStatus::Confirmed,
        ]);

        Booking::create([
            'user_id' => $clientA->id,
            'class_session_id' => $wednesdaySession->id,
            'status' => BookingStatus::Confirmed,
        ]);

        ClassSession::create([
            'topic' => 'Отменённое',
            'starts_at' => $weekStart->copy()->addDays(3)->setTime(12, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Cancelled,
            'trainer_id' => $trainer->id,
        ]);

        $grid = $this->reports->buildWeeklyBookingsGrid($weekStart);

        $this->assertSame([
            'Понедельник 22.06',
            'Вторник 23.06',
            'Среда 24.06',
            'Четверг 25.06',
            'Пятница 26.06',
            'Суббота 27.06',
            'Воскресенье 28.06',
        ], $grid['headers']);

        $this->assertSame([], $grid['columns'][0]);

        $tuesday = $grid['columns'][1];
        $this->assertSame('10:00 Групповой · Ирина Коленцева', $tuesday[0]);
        $this->assertSame('  Иванова Анна Сергеевна', $tuesday[1]);
        $this->assertSame('  Петров Иван Иванович', $tuesday[2]);

        $wednesday = $grid['columns'][2];
        $this->assertSame('18:00 Индивидуальный · Ирина Коленцева', $wednesday[0]);
        $this->assertSame('  Иванова Анна Сергеевна', $wednesday[1]);
        $this->assertSame([], $grid['columns'][3]);

        $export = new WeeklyBookingsExport($weekStart);
        $this->assertCount(3, $export->collection());

        Carbon::setTestNow();
    }
}
