<?php

namespace Tests\Feature\Child;

use App\Livewire\Child\ChatInterface;
use App\Models\Child;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Initiale de l'enfant à côté de ses messages : un prénom qui commence par
 * une lettre accentuée ou arabe ne doit jamais s'afficher en « � ».
 */
class ChildInitialTest extends TestCase
{
    use RefreshDatabase;

    private function chatAs(string $name)
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create(['age' => 9, 'name' => $name]);
        $this->actingAs($child, 'child');

        return Livewire::test(ChatInterface::class)
            ->set('input', 'Salam')->call('sendMessage');
    }

    private function initialOf(string $name): string
    {
        $html = $this->chatAs($name)->html();
        preg_match('/bg-brand-100 text-brand-900[^"]*">\s*(.*?)\s*<\/div>/su', $html, $m);
        $this->assertArrayHasKey(1, $m, 'pastille initiale introuvable');

        return $m[1];
    }

    public function test_accented_first_letter_is_shown_whole(): void
    {
        $this->assertSame('É', $this->initialOf('élias'));
    }

    public function test_arabic_first_letter_is_shown_whole(): void
    {
        $this->assertSame('س', $this->initialOf('سلمى'));
    }

    public function test_latin_first_letter_is_uppercased(): void
    {
        $this->assertSame('Y', $this->initialOf('yasmine'));
    }
}
