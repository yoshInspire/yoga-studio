<?php

namespace Tests\Feature;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Models\ClassSession;
use App\Models\Direction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Расписание на сайте можно сузить до выбранных направлений: ?directions=slug,slug.
 */
class ScheduleDirectionFilterTest extends TestCase
{
    use RefreshDatabase;

    private function direction(string $slug, string $title, int $order): Direction
    {
        return Direction::create([
            'slug' => $slug,
            'num' => sprintf('%02d', $order),
            'sort_order' => $order,
            'title' => $title,
            'lead' => 'Описание.',
            'is_published' => true,
        ]);
    }

    private function classSession(Direction $direction, string $topic, int $inDays = 1): ClassSession
    {
        return ClassSession::create([
            'direction_id' => $direction->id,
            'topic' => $topic,
            'starts_at' => now()->addDays($inDays)->setTime(10, 0),
            'duration_minutes' => 60,
            'type' => SubscriptionType::Group,
            'capacity' => 10,
            'status' => ClassSessionStatus::Scheduled,
        ]);
    }

    public function test_filter_lists_only_directions_with_upcoming_sessions(): void
    {
        $hatha = $this->direction('hatha', 'Хатха-йога', 1);
        $kundalini = $this->direction('kundalini', 'Кундалини-йога', 2);
        $this->direction('nidra', 'Йога-нидра', 3);
        $onlyPast = $this->direction('pranayama', 'Пранаяма', 4);

        $this->classSession($hatha, 'Утренняя практика');
        $this->classSession($kundalini, 'Работа с дыханием');
        $this->classSession($onlyPast, 'Прошедшее', -3);

        $response = $this->get(route('schedule'));

        $response->assertOk();
        $response->assertSee('data-dir-filter="hatha"', false);
        $response->assertSee('data-dir-filter="kundalini"', false);
        // Направление без занятий и направление только с прошедшими — не фильтр.
        $response->assertDontSee('data-dir-filter="nidra"', false);
        $response->assertDontSee('data-dir-filter="pranayama"', false);
    }

    public function test_selected_direction_hides_other_sessions(): void
    {
        $hatha = $this->direction('hatha', 'Хатха-йога', 1);
        $kundalini = $this->direction('kundalini', 'Кундалини-йога', 2);

        $this->classSession($hatha, 'Утренняя практика');
        $this->classSession($kundalini, 'Работа с дыханием');

        $response = $this->get(route('schedule', ['directions' => 'hatha']));

        $response->assertOk();
        $response->assertSee('Утренняя практика');
        $response->assertDontSee('Работа с дыханием');
    }

    public function test_several_directions_can_be_selected_at_once(): void
    {
        $hatha = $this->direction('hatha', 'Хатха-йога', 1);
        $kundalini = $this->direction('kundalini', 'Кундалини-йога', 2);
        $nidra = $this->direction('nidra', 'Йога-нидра', 3);

        $this->classSession($hatha, 'Утренняя практика');
        $this->classSession($kundalini, 'Работа с дыханием');
        $this->classSession($nidra, 'Глубокое расслабление');

        $response = $this->get(route('schedule', ['directions' => 'hatha,nidra']));

        $response->assertOk();
        $response->assertSee('Утренняя практика');
        $response->assertSee('Глубокое расслабление');
        $response->assertDontSee('Работа с дыханием');
    }

    public function test_unknown_slug_is_ignored_and_schedule_stays_full(): void
    {
        $hatha = $this->direction('hatha', 'Хатха-йога', 1);
        $kundalini = $this->direction('kundalini', 'Кундалини-йога', 2);

        $this->classSession($hatha, 'Утренняя практика');
        $this->classSession($kundalini, 'Работа с дыханием');

        $response = $this->get(route('schedule', ['directions' => 'pilates']));

        $response->assertOk();
        $response->assertSee('Утренняя практика');
        $response->assertSee('Работа с дыханием');
    }

    public function test_ajax_response_returns_selected_directions(): void
    {
        $hatha = $this->direction('hatha', 'Хатха-йога', 1);
        $kundalini = $this->direction('kundalini', 'Кундалини-йога', 2);

        $this->classSession($hatha, 'Утренняя практика');
        $this->classSession($kundalini, 'Работа с дыханием');

        $response = $this->getJson(route('schedule', ['directions' => 'hatha', 'ajax' => 1]));

        $response->assertOk();
        $response->assertJson(['selectedDirections' => ['hatha']]);
        $this->assertStringContainsString('Утренняя практика', $response->json('html'));
        $this->assertStringNotContainsString('Работа с дыханием', $response->json('html'));
    }

    public function test_empty_week_under_filter_explains_the_filter(): void
    {
        $hatha = $this->direction('hatha', 'Хатха-йога', 1);
        $kundalini = $this->direction('kundalini', 'Кундалини-йога', 2);

        $this->classSession($hatha, 'Утренняя практика');
        $this->classSession($kundalini, 'Работа с дыханием', 10);

        $response = $this->get(route('schedule', ['directions' => 'kundalini']));

        $response->assertOk();
        $response->assertSee('По выбранным направлениям в этом периоде занятий нет.');
    }
}
