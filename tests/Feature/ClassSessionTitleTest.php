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
}
