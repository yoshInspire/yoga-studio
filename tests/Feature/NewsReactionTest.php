<?php

namespace Tests\Feature;

use App\Enums\NewsReactionType;
use App\Enums\UserRole;
use App\Models\News;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsReactionTest extends TestCase
{
    use RefreshDatabase;

    private function publishedNews(): News
    {
        return News::query()->create([
            'title' => 'Открытие нового класса',
            'body' => 'Текст новости для теста.',
            'is_published' => true,
            'published_at' => now()->subHour(),
        ]);
    }

    public function test_guest_cannot_submit_reaction(): void
    {
        $news = $this->publishedNews();

        $this->postJson(route('news.reactions.store', $news), [
            'reaction' => NewsReactionType::Like->value,
        ])->assertUnauthorized();
    }

    public function test_client_can_add_and_remove_reaction(): void
    {
        $user = User::factory()->create(['role' => UserRole::Client]);
        $news = $this->publishedNews();

        $this->actingAs($user)
            ->postJson(route('news.reactions.store', $news), [
                'reaction' => NewsReactionType::Love->value,
            ])
            ->assertOk()
            ->assertJson([
                'counts' => [
                    'like' => 0,
                    'love' => 1,
                    'fire' => 0,
                    'thanks' => 0,
                ],
                'total' => 1,
                'user_reaction' => 'love',
            ]);

        $this->actingAs($user)
            ->postJson(route('news.reactions.store', $news), [
                'reaction' => NewsReactionType::Love->value,
            ])
            ->assertOk()
            ->assertJson([
                'total' => 0,
                'user_reaction' => null,
            ]);
    }

    public function test_client_can_change_reaction_type(): void
    {
        $user = User::factory()->create(['role' => UserRole::Client]);
        $news = $this->publishedNews();

        $this->actingAs($user)->postJson(route('news.reactions.store', $news), [
            'reaction' => NewsReactionType::Like->value,
        ])->assertOk();

        $this->actingAs($user)
            ->postJson(route('news.reactions.store', $news), [
                'reaction' => NewsReactionType::Fire->value,
            ])
            ->assertOk()
            ->assertJson([
                'counts' => [
                    'like' => 0,
                    'fire' => 1,
                ],
                'total' => 1,
                'user_reaction' => 'fire',
            ]);

        $this->assertDatabaseCount('news_reactions', 1);
    }

    public function test_trainer_can_submit_reaction(): void
    {
        $trainer = User::factory()->trainer()->create();
        $news = $this->publishedNews();

        $this->actingAs($trainer)
            ->postJson(route('news.reactions.store', $news), [
                'reaction' => NewsReactionType::Thanks->value,
            ])
            ->assertOk()
            ->assertJson([
                'counts' => [
                    'thanks' => 1,
                ],
                'total' => 1,
                'user_reaction' => 'thanks',
            ]);
    }

    public function test_admin_cannot_submit_reaction(): void
    {
        $admin = User::factory()->admin()->create();
        $news = $this->publishedNews();

        $this->actingAs($admin)
            ->postJson(route('news.reactions.store', $news), [
                'reaction' => NewsReactionType::Like->value,
            ])
            ->assertForbidden();
    }

    public function test_news_page_shows_reactions_block(): void
    {
        $news = $this->publishedNews();

        $this->get(route('news.show', $news))
            ->assertOk()
            ->assertSee('data-news-reactions', false);
    }
}
