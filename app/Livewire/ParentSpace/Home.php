<?php

namespace App\Livewire\ParentSpace;

use App\Livewire\Concerns\ResolvesParentChildren;
use App\Models\ParentSynthesis;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Accueil parent (lot 1 §5.1) : choix de l'enfant, dernière synthèse en quatre blocs. */
#[Layout('layouts.parent')]
class Home extends Component
{
    use ResolvesParentChildren;

    #[Url(as: 'enfant')]
    public ?int $childId = null;

    public function mount(): void
    {
        $this->childId = $this->ownChild($this->childId)->id;
    }

    public function selectChild(int $childId): void
    {
        $this->childId = $this->ownChild($childId)->id;
    }

    public function render()
    {
        $children = $this->parentChildren();
        $child = $this->ownChild($this->childId);

        $synthesis = ParentSynthesis::query()
            ->where('child_id', $child->id)
            ->where('parent_id', Auth::id())
            ->latest('sent_at')
            ->first();

        if ($synthesis && $synthesis->read_at === null) {
            $synthesis->forceFill(['read_at' => now()])->save();
        }

        $previous = ParentSynthesis::query()
            ->where('child_id', $child->id)->where('parent_id', Auth::id())
            ->when($synthesis, fn ($q) => $q->where('id', '!=', $synthesis->id))
            ->latest('sent_at')->limit(5)->get();

        return view('livewire.parent-space.home', [
            'children'  => $children,
            'child'     => $child,
            'synthesis' => $synthesis,
            'previous'  => $previous,
        ]);
    }
}
