<?php
namespace App\Livewire\Child;

use App\Models\Alert;
use App\Models\ChatSession;
use App\Services\AIService;
use App\Services\CrisisDetector;
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

    /** Pire zone atteinte au cours de la session (max running). */
    public string $currentZone = 'green';

    /** Type d'alerte courant (priorité au plus récent typé). */
    public ?string $currentAlertType = null;

    /** Indique si une alerte temps réel a déjà été créée pour la session (idempotence). */
    public bool $alertCreated = false;

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
        $detector = app(CrisisDetector::class);

        // 1) Filet de sécurité déterministe sur le DERNIER message enfant.
        $lastUser = collect($this->messages)->reverse()->firstWhere('role', 'user');
        $deterministic = $lastUser
            ? $detector->evaluate($lastUser['content'], $this->currentZone)
            : ['zone' => $this->currentZone, 'alert_type' => null, 'matched' => false];

        // 2) Demande à l'IA.
        $aiMessages = collect($this->messages)
            ->filter(fn($m) => $m['role'] !== 'assistant' || count($this->messages) > 1)
            ->values()
            ->toArray();

        $aiResult = null;
        try {
            $aiResult = app(AIService::class)->chat($aiMessages, $child->age);
        } catch (\Throwable $e) {
            Log::error('Chat AI failure', [
                'child_id' => $child->id,
                'session'  => $this->sessionId,
                'error'    => $e->getMessage(),
            ]);
        }

        // 3) Fusion : on prend le pire des deux signaux ; on garde l'alert_type prioritaire.
        $aiZone       = $aiResult['zone'] ?? $this->currentZone;
        $mergedZone   = $detector->maxZone(
            $detector->maxZone($this->currentZone, $aiZone),
            $deterministic['zone']
        );

        $aiAlertType  = $aiResult['alert_type'] ?? null;
        $alertType    = $deterministic['alert_type'] ?? $aiAlertType ?? $this->currentAlertType;

        $reply = $aiResult['message'] ?? null;
        if ($reply === null || trim($reply) === '') {
            $reply = $this->safeFallback($mergedZone);
        }

        // 4) Si le filet déterministe détecte ROUGE alors que l'IA n'a pas viré rouge,
        //    on remplace la réponse par un message de mode sécurité (P10, P13).
        if ($deterministic['zone'] === 'red' && ($aiResult['is_critical'] ?? false) === false) {
            $reply = $this->safetyMessage($child->age_group);
        }

        $this->currentZone = $mergedZone;
        $this->currentAlertType = $alertType;

        $this->messages[] = ['role' => 'assistant', 'content' => $reply];
        $this->isTyping = false;
        $this->dispatch('scroll-bottom');

        // 5) Création d'alerte temps réel (P9, P11, P13).
        $this->maybeCreateAlert($child);
    }

    public function endSession(): void
    {
        if ($this->sessionClosed || ! $this->sessionId) return;

        $child = Auth::guard('child')->user();
        $session = ChatSession::find($this->sessionId);
        if (! $session) return;

        $detector = app(CrisisDetector::class);
        $aiMessages = collect($this->messages)->slice(1)->values()->toArray();

        try {
            $analysis = app(AIService::class)->analyzeSession($aiMessages, $child->age);

            // On garde la PIRE zone observée pendant la session (P11) :
            // l'IA peut redescendre artificiellement à la fin, on protège ça.
            $finalZone = $detector->maxZone($this->currentZone, $analysis['zone']);
            $finalAlertType = $analysis['alert_type'] ?? $this->currentAlertType;

            $session->update([
                'ended_at'       => now(),
                'zone'           => $finalZone,
                'ai_summary'     => $analysis['summary'],
                'low_confidence' => $analysis['lowConfidence'],
            ]);

            // Création d'alerte de fin uniquement si aucune n'a déjà été créée
            // en temps réel pendant la conversation (idempotence).
            if (! $this->alertCreated && in_array($finalZone, ['orange', 'red'], true)) {
                Alert::create([
                    'session_id' => $session->id,
                    'child_id'   => $child->id,
                    'school_id'  => $child->school_id,
                    'type'       => $finalAlertType ?? 'detresse',
                    'level'      => $finalZone === 'red' ? 'critical' : 'moderate',
                ]);
                $this->alertCreated = true;
            }

            \App\Jobs\ProcessSessionClosure::dispatch($session);
        } catch (\Throwable $e) {
            Log::error('Session analysis failure', [
                'session' => $session->id,
                'error'   => $e->getMessage(),
            ]);
            // Même en échec d'analyse, on persiste la pire zone observée côté backend.
            $session->update([
                'ended_at'       => now(),
                'zone'           => $this->currentZone,
                'low_confidence' => true,
            ]);
        }

        $this->sessionClosed = true;
        $this->messages[] = [
            'role'    => 'assistant',
            'content' => "Merci de m'avoir parlé aujourd'hui. 🌿 Prends soin de toi !",
        ];
    }

    /**
     * Crée une alerte temps réel selon la zone courante (P9, P11, P13).
     * Idempotence : la propriété Livewire $alertCreated est sérialisée côté client donc
     * potentiellement manipulable. On double-checke côté serveur via une requête DB.
     */
    private function maybeCreateAlert($child): void
    {
        if ($this->alertCreated || ! $this->sessionId) return;
        if (! in_array($this->currentZone, ['orange', 'red'], true)) return;

        if (Alert::where('session_id', $this->sessionId)->exists()) {
            $this->alertCreated = true;
            return;
        }

        try {
            Alert::create([
                'session_id' => $this->sessionId,
                'child_id'   => $child->id,
                'school_id'  => $child->school_id,
                'type'       => $this->currentAlertType ?? 'detresse',
                'level'      => $this->currentZone === 'red' ? 'critical' : 'moderate',
            ]);
            $this->alertCreated = true;
        } catch (\Throwable $e) {
            Log::error('Realtime alert creation failed', [
                'session' => $this->sessionId,
                'zone'    => $this->currentZone,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fallback DOUX et CONTEXTUEL si l'IA échoue ou renvoie un texte vide.
     * Ne réutilise PAS la phrase générique « Je suis là pour toi… » signalée par la QA (P2).
     */
    private function safeFallback(string $zone): string
    {
        $candidates = match ($zone) {
            'red'    => [
                "Je t'entends. Ce que tu vis est lourd, et tu n'as pas à rester seul avec ça. Est-ce qu'il y a un adulte près de toi à qui tu fais confiance ?",
            ],
            'orange' => [
                "C'est important ce que tu me dis. Est-ce que ça arrive souvent ?",
                "Merci de m'avoir confié ça. Tu te sens comment maintenant, juste à l'instant ?",
            ],
            'yellow' => [
                "Je comprends. Qu'est-ce qui t'a le plus pesé aujourd'hui ?",
                "On peut respirer ensemble si tu veux : on inspire 4 secondes, on garde 4 secondes, on souffle 4 secondes. Tu veux essayer ?",
            ],
            default  => [
                "D'accord. Raconte-moi un peu plus, si tu veux.",
                "Je t'écoute. Qu'est-ce que tu as fait de chouette aujourd'hui ?",
            ],
        };

        return $candidates[array_rand($candidates)];
    }

    /**
     * Message de mode sécurité quand le filet déterministe détecte un signal rouge
     * que l'IA aurait raté (P10, P13).
     */
    private function safetyMessage(string $ageGroup): string
    {
        return match ($ageGroup) {
            '5-7'   => "Ce que tu me dis est très important. 💛 Tu n'es pas tout seul. Va voir un grand qui s'occupe bien de toi (papa, maman, maîtresse, infirmière) et raconte-lui maintenant.",
            '8-11'  => "Ce que tu me dis compte beaucoup. Tu n'as pas à rester seul avec ça. S'il te plaît, va parler à un adulte de confiance maintenant — un parent, un prof, l'infirmière de l'école. Tu peux aussi appeler le 141.",
            default => "Ce que tu traverses est lourd, et tu n'es pas seul. Le plus important maintenant, c'est d'en parler à un adulte de confiance — un parent, un enseignant, l'infirmière scolaire. Au Maroc tu peux aussi appeler gratuitement le 141. S'il te plaît, ne reste pas seul avec ça.",
        };
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
