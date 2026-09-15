<?php

namespace Tests\Feature\Child;

use App\Livewire\Child\ChatInterface;
use App\Models\Child;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * D4 (MVP v3) — avatar fixe de Care dans le chat (discret pour 12-18).
 */
class CareAvatarTest extends TestCase
{
    use RefreshDatabase;

    private function loginChild(int $age): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create(['age' => $age]);
        $this->actingAs($child, 'child');
    }

    public function test_avatar_file_exists(): void
    {
        $this->assertFileExists(public_path('img/care/care-avatar.png'));
    }

    public function test_young_child_sees_header_avatar_and_bubble_thumbnail(): void
    {
        $this->loginChild(9);

        Livewire::test(ChatInterface::class)
            ->assertSee('img/care/care-avatar.png')
            ->assertSee('alt="Care"', false)
            ->assertSee('data-care-avatar="header"', false)
            ->assertSee('data-care-avatar="bubble"', false);
    }

    public function test_teenager_sees_header_avatar_only(): void
    {
        $this->loginChild(15);

        Livewire::test(ChatInterface::class)
            ->assertSee('data-care-avatar="header"', false)
            ->assertDontSee('data-care-avatar="bubble"', false);
    }
}
