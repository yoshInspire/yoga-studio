<?php

namespace Tests\Feature;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Models\ClassSession;
use App\Models\Direction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassSessionTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_compose_title_from_direction_and_topic(): void
    {
        $direction = Direction::query()->create([
            'slug' => 'power-pilates',
            'num' => '01',
            'sort_order' => 1,
            'title' => 'Power Pilates',
            'lead' => 'Test direction',
            'is_published' => true,
        ]);

        $session = ClassSession::query()->create([
            'direction_id' => $direction->id,
            'topic' => 'Создание рельефа',
            'starts_at' => now()->addDay(),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        $this->assertSame('Power Pilates · Создание рельефа', $session->title);
    }

    public function test_compose_title_from_topic_only(): void
    {
        $session = ClassSession::query()->create([
            'topic' => 'Йога-нидра',
            'starts_at' => now()->addDay(),
            'type' => SubscriptionType::SpecialEvent,
            'capacity' => 10,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        $this->assertSame('Йога-нидра', $session->title);
    }

    public function test_custom_duration_is_used_in_schedule(): void
    {
        $session = ClassSession::query()->create([
            'topic' => 'Тест',
            'starts_at' => now()->addDay()->setTime(10, 0),
            'type' => SubscriptionType::Group,
            'duration_minutes' => 75,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        $this->assertSame(75, $session->durationMinutes());
        $this->assertSame('10:00–11:15', $session->formattedTimeRange());
    }

    public function test_duration_falls_back_to_type_default(): void
    {
        config(['studio.default_class_duration_minutes' => [
            'group' => 90,
            'default' => 90,
        ]]);

        $session = ClassSession::query()->create([
            'topic' => 'Тест',
            'starts_at' => now()->addDay()->setTime(14, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        $this->assertSame(90, $session->durationMinutes());
    }
}
