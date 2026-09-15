<?php

namespace App\Livewire\ParentSpace;

use App\Livewire\Concerns\ResolvesParentChildren;
use App\Services\ParentJournalBuilder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Journal parent (lot 1 §5.2) : événements macro, jamais le type, la qualification ni les notes. */
#[Layout('layouts.parent')]
class Journal extends Component
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

    public function render(ParentJournalBuilder $builder)
    {
        $children = $this->parentChildren();
        $child = $this->ownChild($this->childId);

        return view('livewire.parent-space.journal', [
            'children' => $children,
            'child'    => $child,
            'events'   => $builder->build($child),
        ]);
    }
}
