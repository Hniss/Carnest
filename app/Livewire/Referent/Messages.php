<?php

namespace App\Livewire\Referent;

use App\Livewire\Concerns\ResolvesReferentAccess;
use App\Models\Child;
use App\Models\ParentMessage;
use App\Models\ParentThread;
use App\Models\School;
use App\Models\User;
use App\Services\Audit;
use App\Services\Notifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Messagerie parent ↔ référent, côté référent (lot 1 §3.5). */
#[Layout('layouts.app')]
class Messages extends Component
{
    use ResolvesReferentAccess;

    #[Url(as: 'fil')]
    public ?int $threadId = null;

    #[Url(as: 'enfant')]
    public ?int $childFilter = null;

    public string $body = '';

    public bool $composing = false;
    public ?int $newChildId = null;
    public ?int $newParentId = null;
    public string $newSubject = '';
    public string $newBody = '';

    protected School $school;

    public function mount(): void
    {
        $this->school = $this->resolveReferentSchool();
        $this->denyDelegate();
        if ($this->childFilter) {
            $this->composing = true;
            $this->newChildId = $this->childFilter;
        }
        if ($this->threadId) {
            $this->selectThread($this->threadId);
        }
    }

    public function hydrate(): void
    {
        $this->school = $this->resolveReferentSchool();
        $this->denyDelegate();
    }

    private function threadOrFail(int $id): ParentThread
    {
        $thread = ParentThread::find($id);
        abort_unless($thread, 404);
        abort_unless($thread->school_id === $this->school->id, 403);
        return $thread;
    }

    public function selectThread(int $id): void
    {
        $thread = $this->threadOrFail($id);
        $this->threadId = $thread->id;
        $this->composing = false;
        $thread->messages()->where('sender_role', 'parent')->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function toggleImportant(int $id): void
    {
        $thread = $this->threadOrFail($id);
        $thread->update(['important' => ! $thread->important]);
    }

    public function send(): void
    {
        abort_unless($this->threadId, 422);
        $thread = $this->threadOrFail($this->threadId);
        $this->validate(['body' => ['required', 'string', 'min:1', 'max:4000']], [], ['body' => 'message']);

        DB::transaction(function () use ($thread) {
            ParentMessage::create([
                'thread_id' => $thread->id, 'sender_id' => Auth::id(), 'sender_role' => 'referent',
                'body' => trim($this->body), 'sent_at' => now(),
            ]);
            $thread->touch();
            if ($thread->referent_id === null) {
                $thread->update(['referent_id' => Auth::id()]);
            }
        });
        app(Notifier::class)->notify($thread->parent, 'message', 'Nouveau message du référent', 'Fil : ' . $thread->subject, '/parent/messages?fil=' . $thread->id);
        Audit::log('referent.message.send', $thread);
        $this->reset('body');
    }

    public function startCompose(): void
    {
        $this->composing = true;
        $this->threadId = null;
    }

    public function createThread(): void
    {
        $this->validate([
            'newChildId'  => ['required', 'integer'],
            'newParentId' => ['required', 'integer'],
            'newSubject'  => ['required', 'string', 'max:150'],
            'newBody'     => ['required', 'string', 'max:4000'],
        ], [], ['newChildId' => 'élève', 'newParentId' => 'parent', 'newSubject' => 'objet', 'newBody' => 'message']);

        $child = Child::where('school_id', $this->school->id)->find($this->newChildId);
        abort_unless($child, 403);
        $parent = $child->consentingParents()->where('users.id', $this->newParentId)->first();
        abort_unless($parent, 403);

        $thread = DB::transaction(function () use ($child, $parent) {
            $thread = ParentThread::create([
                'school_id' => $this->school->id, 'child_id' => $child->id, 'parent_id' => $parent->id,
                'referent_id' => Auth::id(), 'subject' => trim($this->newSubject),
            ]);
            ParentMessage::create([
                'thread_id' => $thread->id, 'sender_id' => Auth::id(), 'sender_role' => 'referent',
                'body' => trim($this->newBody), 'sent_at' => now(),
            ]);
            return $thread;
        });
        app(Notifier::class)->notify($parent, 'message', 'Nouveau message du référent', 'Fil : ' . $thread->subject, '/parent/messages?fil=' . $thread->id);
        Audit::log('referent.message.send', $thread);

        $this->reset('newChildId', 'newParentId', 'newSubject', 'newBody', 'composing', 'childFilter');
        $this->threadId = $thread->id;
    }

    public function render()
    {
        $threads = ParentThread::query()
            ->where('school_id', $this->school->id)
            ->with(['parent:id,name', 'child:id,name,classe'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('sender_role', 'parent')->whereNull('read_at')])
            ->orderByDesc('important')->latest('updated_at')
            ->get();

        $current = $this->threadId ? $threads->firstWhere('id', $this->threadId)?->load('messages.sender') : null;

        $children = Child::where('school_id', $this->school->id)->whereHas('consentingParents')->orderBy('name')->get(['id', 'name', 'classe']);
        $parents  = $this->newChildId
            ? Child::where('school_id', $this->school->id)->find($this->newChildId)?->consentingParents()->get(['users.id', 'users.name']) ?? collect()
            : collect();

        return view('livewire.referent.messages', [
            'school'   => $this->school,
            'threads'  => $threads,
            'current'  => $current,
            'children' => $children,
            'parents'  => $parents,
            'myRole'   => 'referent',
        ]);
    }
}
