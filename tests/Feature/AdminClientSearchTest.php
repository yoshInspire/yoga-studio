<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Поиск клиента администратором (запись клиента на сайте и в приложении).
 * Ирина, 16.11.2026: ребёнка без входа находить по телефону мамы.
 */
class AdminClientSearchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /** @return array{0: User, 1: User} мама и ребёнок без входа */
    private function family(): array
    {
        $mom = User::factory()->create([
            'role' => UserRole::Client,
            'first_name' => 'Анастасия',
            'last_name' => 'Фадеева',
            'phone' => '79164397663',
        ]);

        $child = User::factory()->create([
            'role' => UserRole::Client,
            'first_name' => 'Константин',
            'last_name' => 'Фадеев',
            'phone' => null,
            'email' => null,
            'guardian_id' => $mom->id,
        ]);

        return [$mom, $child];
    }

    private function ids(string $q): array
    {
        return collect($this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/clients?q='.urlencode($q))
            ->assertOk()
            ->json('data'))->pluck('id')->all();
    }

    public function test_child_is_found_by_guardian_phone_in_any_format(): void
    {
        [$mom, $child] = $this->family();

        foreach (['+7 (916) 439-76-63', '89164397663', '9164397663', '4397663', '8 916 439'] as $q) {
            $ids = $this->ids($q);
            $this->assertContains($mom->id, $ids, $q);
            $this->assertContains($child->id, $ids, $q);
        }
    }

    public function test_full_name_and_short_digits_search(): void
    {
        [$mom, $child] = $this->family();

        $this->assertSame([$child->id], $this->ids('Фадеев Константин'));
        $this->assertSame([$child->id], $this->ids('Константин'));
        // Меньше четырёх цифр — не телефон, ничего лишнего не находим.
        $this->assertSame([], $this->ids('91'));
    }

    public function test_api_marks_child_and_shows_guardian_phone(): void
    {
        [, $child] = $this->family();

        $row = collect($this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/clients?q=4397663')
            ->json('data'))->firstWhere('id', $child->id);

        $this->assertTrue($row['is_child']);
        $this->assertStringContainsString('(916) 439-76-63', $row['phone']);
    }

    public function test_picker_label_marks_child_with_guardian(): void
    {
        [$mom, $child] = $this->family();

        $this->assertSame($child->fullName().' · ребёнок ('.$mom->shortName().')', $child->adminPickerLabel());
        $this->assertSame($mom->fullName(), $mom->adminPickerLabel());
    }
}
