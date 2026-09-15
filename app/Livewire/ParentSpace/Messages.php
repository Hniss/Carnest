<?php

namespace App\Livewire\ParentSpace;

use App\Livewire\Concerns\ResolvesParentChildren;
use App\Models\ParentMessage;
use App\Models\ParentThread;
use App\Services\Audit;
use App\Services\Notifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Messagerie parent (lot 1 §5.3) : uniquement avec le référent de l'école de l'enfant. */
#[Layout('layouts.parent')]
class Messages extends Component
{
    use ResolvesParentChildren;

    #[Url(as: 'fil')]
    public ?int $threadId = null;

    #[Url(as: 'enfant')]
    public ?int $childFilter = null;

    public string $body = '';

    public bool $composing = false;
    public ?int $newChildId = null;
    public string $newSubject = '';
    public string $newBody = '';

    public function mount(): void
    {
        if ($this->childFilter) {
            $this->composing = true;
            $this->newChildId = $this->ownChild($this->childFilter)->id;
        }
        if ($this->threadId) {
            $this->selectThread($this->threadId);
        }
    }

    private function threadOrFail(int $id): ParentThread
    {
        $thread = ParentThread::find($id);
        abort_unless($thread, 403);
        abort_unless($thread->parent_id === Auth::id(), 403);
        return $thread;
    }

    public function selectThread(int $id): void
    {
        $thread = $this->threadOrFail($id);
        $this->threadId = $thread->id;
        $this->composing = false;
        $thread->messages()->where('sender_role', 'referent')->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function startCompose(): void
    {
        $this->composing = true;
        $this->threadId = null;
        $children = $this->parentChildren();
        if ($children->count() === 1) {
            $this->newChildId = $children->first()->id;
        }
    }

    private function notifyReferent(ParentThread $thread): void
    {
        $referent = $thread->referent ?? $thread->school->referent();
        if ($referent) {
            app(Notifier::class)->notify($referent, 'message', 'Nouveau message d\'un parent', 'Fil : ' . $thread->subject, '/dashboard-referent/messages?fil=' . $thread->id);
        }
    }

    public function send(): void
    {
        abort_unless($this->threadId, 422);
        $thread = $this->threadOrFail($this->threadId);
        $this->validate(['body' => ['required', 'string', 'min:1', 'max:4000']], [], ['body' => 'message']);

        DB::transaction(function () use ($thread) {
            ParentMessage::create([
                'thread_id' => $thread->id, 'sender_id' => Auth::id(), 'sender_role' => 'parent',
                'body' => trim($this->body), 'sent_at' => now(),
            ]);
            $thread->touch();
        });
        $this->notifyReferent($thread);
        Audit::log('parent.message.send', $thread);
        $this->reset('body');
    }

    public function createThread(): void
    {
        $this->validate([
            'newChildId' => ['required', 'integer'],
            'newSubject' => ['required', 'string', 'max:150'],
            'newBody'    => ['required', 'string', 'max:4000'],
        ], [], ['newChildId' => 'enfant', 'newSubject' => 'objet', 'newBody' => 'message']);

        $child = $this->ownChild($this->newChildId);
        $referent = $child->school->referent();

        $thread = DB::transaction(function () use ($child, $referent) {
            $thread = ParentThread::create([
                'school_id' => $child->school_id, 'child_id' => $child->id, 'parent_id' => Auth::id(),
                'referent_id' => $referent?->id, 'subject' => trim($this->newSubject),
            ]);
            ParentMessage::create([
                'thread_id' => $thread->id, 'sender_id' => Auth::id(), 'sender_role' => 'parent',
                'body' => trim($this->newBody), 'sent_at' => now(),
            ]);
            return $thread;
        });
        $this->notifyReferent($thread);
        Audit::log('parent.message.send', $thread);

        $this->reset('newChildId', 'newSubject', 'newBody', 'composing', 'childFilter');
        $this->threadId = $thread->id;
    }

    public function render()
    {
        $children = $this->parentChildren();

        $threads = ParentThread::query()
            ->where('parent_id', Auth::id())
            ->with(['child:id,name'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('sender_role', 'referent')->whereNull('read_at')])
            ->latest('updated_at')
            ->get();

        $current = $this->threadId ? $threads->firstWhere('id', $this->threadId)?->load('messages.sender') : null;

        return view('livewire.parent-space.messages', [
            'children' => $children,
            'threads'  => $threads,
            'current'  => $current,
            'myRole'   => 'parent',
        ]);
    }
}
