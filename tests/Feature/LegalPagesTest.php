<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Правовые страницы обязаны открываться гостю: их проверяют роботы App Store
 * и Google Play, а приложение показывает документы до входа. Стоит кому-то
 * случайно накрыть их middleware `auth` — публикация приложения встанет.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_policy_is_public(): void
    {
        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('Политика в отношении обработки персональных данных')
            ->assertSee('Коленцева');
    }

    public function test_privacy_policy_lists_the_data_the_app_collects(): void
    {
        $response = $this->get(route('legal.privacy'))->assertOk();

        $response->assertSee('push-уведомлений', false);
        $response->assertSee('состоянии здоровья', false);
        $response->assertSee('ЮKassa', false);
    }

    public function test_account_deletion_page_is_public(): void
    {
        $this->get(route('legal.account-delete'))
            ->assertOk()
            ->assertSee('Удаление аккаунта');
    }

    public function test_documents_are_listed_for_the_mobile_app(): void
    {
        $response = $this->getJson('/api/v1/legal')->assertOk();

        $slugs = array_column($response->json('data'), 'slug');

        $this->assertSame(['offer', 'privacy', 'account-delete'], $slugs);
        $this->assertSame(route('legal.privacy'), $response->json('data.1.url'));
    }

    public function test_footer_links_to_the_documents(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('legal.offer'), false)
            ->assertSee(route('legal.privacy'), false);
    }
}
