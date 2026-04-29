<?php
namespace App\Livewire\Child;

use App\Models\ChatSession;
use App\Services\AIService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.child')]
class ChatInterface extends Component
{
    public array $messages = [];
    public string $input = '';
    public bool $isTyping = false;
    public bool $sessionClosed = false;
    public ?int $sessionId = null;

    public function mount(): void
    {
        $child = Auth::guard('child')->user();
        $welcome = $this->getWelcome($child->age_group);
        $this->messages[] = ['role' => 'assistant', 'content' => $welcome];

        $session = ChatSession::create([
            'child_id'   => $child->id,
            'school_id'  => $child->school_id,
            'started_at' => now(),
        ]);
        $this->sessionId = $session->id;
    }

    public function sendMessage(): void
    {
        if (empty(trim($this->input)) || $this->sessionClosed) return;

        $text = trim($this->input);
        $this->input = '';
        $this->messages[] = ['role' => 'user', 'content' => $text];
        $this->isTyping = true;
        $this->dispatch('scroll-bottom');
    }

    public function fetchReply(): void
    {
        if (! $this->isTyping) return;

        $child = Auth::guard('child')->user();
        $aiMessages = collect($this->messages)
            ->filter(fn($m) => $m['role'] !== 'assistant' || count($this->messages) > 1)
            ->values()
            ->toArray();

        try {
            $reply = app(AIService::class)->chat($aiMessages, $child->age);
        } catch (\Throwable $e) {
            Log::error('Chat AI failure', [
                'child_id' => $child->id,
                'session'  => $this->sessionId,
                'error'    => $e->getMessage(),
            ]);
            $reply = "Je suis là pour toi. Tu peux me dire ce que tu ressens ? 🌿";
        }

        $this->messages[] = ['role' => 'assistant', 'content' => $reply];
        $this->isTyping = false;
        $this->dispatch('scroll-bottom');
    }

    public function endSession(): void
    {
        if ($this->sessionClosed || ! $this->sessionId) return;

        $child = Auth::guard('child')->user();
        $session = ChatSession::find($this->sessionId);
        if (! $session) return;

        $aiMessages = collect($this->messages)->slice(1)->values()->toArray();

        try {
            $analysis = app(AIService::class)->analyzeSession($aiMessages, $child->age);
            $session->update([
                'ended_at'       => now(),
                'zone'           => $analysis['zone'],
                'ai_summary'     => $analysis['summary'],
                'low_confidence' => $analysis['lowConfidence'],
            ]);

            if (in_array($analysis['zone'], ['orange', 'red'])) {
                \App\Models\Alert::create([
                    'session_id' => $session->id,
                    'child_id'   => $child->id,
                    'school_id'  => $child->school_id,
                    'type'       => 'detresse',
                    'level'      => $analysis['zone'] === 'red' ? 'critical' : 'moderate',
                ]);
            }

            \App\Jobs\ProcessSessionClosure::dispatch($session);
        } catch (\Throwable $e) {
            Log::error('Session analysis failure', [
                'session' => $session->id,
                'error'   => $e->getMessage(),
            ]);
            $session->update(['ended_at' => now(), 'zone' => 'green', 'low_confidence' => true]);
        }

        $this->sessionClosed = true;
        $this->messages[] = [
            'role'    => 'assistant',
            'content' => "Merci de m'avoir parlé aujourd'hui. 🌿 Prends soin de toi !",
        ];
    }

    private function getWelcome(string $ageGroup): string
    {
        return match($ageGroup) {
            '5-7'   => "Salut ! 😊 Moi c'est Care ! Comment tu vas aujourd'hui ?",
            '8-11'  => "Hey ! Contente de te voir 😊 Tu peux me dire comment s'est passée ta journée ?",
            default => "Bonjour ! Je suis là pour toi. Comment tu te sens en ce moment ?",
        };
    }

    public function render()
    {
        return view('livewire.child.chat-interface');
    }
}
