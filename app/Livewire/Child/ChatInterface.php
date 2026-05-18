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

    /**
     * Compteur d'échecs IA consécutifs (Gemini 503, timeout, parse vide).
     * Sert à basculer sur un message dégradé honnête après 2 échecs d'affilée
     * plutôt que d'enchaîner les fallbacks génériques qui donnent l'impression
     * d'une boucle (bug remonté UI : 2 fallbacks « Je t'écoute… » / « D'accord, je suis là… »).
     */
    public int $consecutiveFailures = 0;

    /**
     * Dernier fallback servi (texte). Permet de NE JAMAIS renvoyer deux fois de
     * suite la même phrase de secours quand l'IA enchaîne les échecs.
     */
    public ?string $lastFallback = null;

    public function mount(): void
    {
        $child = Auth::guard('child')->user();
        $welcome = $this->getWelcome($child->age_group);
        $this->messages[] = ['role' => 'assistant', 'content' => $welcome];

        // P2 (V4) : on initialise last_activity_at dès la création — c'est ce
        // timestamp que le job CloseIdleSessions utilise pour détecter une
        // inactivité ≥ 5 min et fermer la session proprement.
        $session = ChatSession::create([
            'child_id'         => $child->id,
            'school_id'        => $child->school_id,
            'started_at'       => now(),
            'last_activity_at' => now(),
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

        // P2 (V4) : on touche last_activity_at + zone à chaque message enfant.
        // On ne stocke JAMAIS le contenu du message — uniquement le timestamp
        // et la pire zone observée à ce stade.
        if ($this->sessionId) {
            ChatSession::whereKey($this->sessionId)->update([
                'last_activity_at' => now(),
                'zone'             => $this->currentZone,
            ]);
        }

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
        // P4 : on retire systématiquement le welcome (premier message assistant
        // avant tout échange) pour que Gemini reçoive le system prompt propre
        // dès le premier message de l'enfant. Sinon le bot répond parfois de
        // manière générique au tout premier tour.
        $aiMessages = collect($this->messages)
            ->skipWhile(fn ($m) => $m['role'] === 'assistant')
            ->values()
            ->toArray();

        $aiResult = null;
        try {
            $aiResult = app(AIService::class)->chat($aiMessages, $child->age, $child->gender ?? null);
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
        $aiFailed = $reply === null || trim($reply) === '';
        if ($aiFailed) {
            $this->consecutiveFailures++;
            $reply = $this->buildFallback($mergedZone, $this->consecutiveFailures);
            $this->lastFallback = $reply;
        } else {
            $this->consecutiveFailures = 0;
            $this->lastFallback = null;
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

        // P2 (V4) : on persiste l'état courant de la session — last_activity_at,
        // pire zone observée, low_confidence selon résultat IA. Permet au job
        // CloseIdleSessions de finaliser proprement une session abandonnée.
        if ($this->sessionId) {
            ChatSession::whereKey($this->sessionId)->update([
                'last_activity_at' => now(),
                'zone'             => $this->currentZone,
                'low_confidence'   => $aiResult['low_confidence'] ?? false,
            ]);
        }

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
            $analysis = app(AIService::class)->analyzeSession($aiMessages, $child->age, $child->gender ?? null);

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
                    'level'      => $finalZone === 'red'
                        ? 'critical'
                        : app(\App\Services\AlertLevelResolver::class)->resolve(
                            $finalAlertType,
                            $finalZone,
                            collect($this->messages)->where('role', 'user')->pluck('content')->all()
                        ),
                ]);
                $this->alertCreated = true;
            }

            // P1/P6 : update synchrone du child (last_session_at, score, status)
            // pour que le dashboard reflète l'état immédiatement, sans worker queue.
            \App\Jobs\ProcessSessionClosure::dispatchSync($session);
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
            // On lance quand même le calcul de score pour que la dernière session
            // remonte au dashboard (P1).
            \App\Jobs\ProcessSessionClosure::dispatchSync($session);
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
            $level = $this->currentZone === 'red'
                ? 'critical'
                : app(\App\Services\AlertLevelResolver::class)->resolve(
                    $this->currentAlertType,
                    $this->currentZone,
                    collect($this->messages)->where('role', 'user')->pluck('content')->all()
                );

            Alert::create([
                'session_id' => $this->sessionId,
                'child_id'   => $child->id,
                'school_id'  => $child->school_id,
                'type'       => $this->currentAlertType ?? 'detresse',
                'level'      => $level,
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
     * Construit un fallback contextuel quand l'IA échoue (Gemini 503, parse vide).
     *
     * Anti-boucle (bug remonté V5) — règles :
     *  1. Au DEUXIÈME échec consécutif zone neutre/légère, sert un message honnête
     *     de dégradation plutôt qu'une 2e phrase générique qui donne l'impression
     *     de boucler.
     *  2. Anti-répétition : si la phrase candidate est identique au dernier fallback,
     *     on prend la suivante de la liste.
     */
    private function buildFallback(string $zone, int $failures): string
    {
        // P3 : un fallback ne change PAS brusquement de sujet si l'enfant a déjà
        // exprimé une émotion (zone yellow/orange/red).
        if ($failures >= 2 && in_array($zone, ['green', 'yellow'], true)) {
            // 2e échec d'affilée sur tonalité neutre/légère : on assume
            // honnêtement que le service rame et on invite à reformuler.
            $degraded = [
                "Pardon, j'ai un peu de mal à te répondre là tout de suite. Tu veux bien me redire ?",
                "Désolée, ma réponse n'arrive pas comme il faut. Tu peux reformuler en une phrase courte ?",
                "Excuse-moi, j'ai un petit souci de connexion. Redis-moi en quelques mots ?",
            ];
            return $this->pickDistinct($degraded);
        }

        $candidates = match ($zone) {
            'red'    => [
                "Je t'entends. Ce que tu vis est lourd, et tu n'as pas à rester seul avec ça. Est-ce qu'il y a un adulte près de toi à qui tu fais confiance ?",
                "Merci de me l'avoir dit. C'est trop pour toi tout seul — il faut qu'un adulte de confiance soit au courant. Qui est près de toi en ce moment ?",
            ],
            'orange' => [
                "C'est important ce que tu me dis. Est-ce que ça arrive souvent ?",
                "Merci de m'avoir confié ça. Tu peux me raconter un peu plus ce qui s'est passé ?",
                "Je vois que c'est dur. Depuis quand ça se passe comme ça ?",
            ],
            'yellow' => [
                "Je comprends que ça t'ait pesé. Qu'est-ce qui t'a le plus dérangé aujourd'hui ?",
                "On peut respirer ensemble si tu veux : on inspire 4 secondes, on garde 4 secondes, on souffle 4 secondes. Tu veux essayer ?",
                "Ça a l'air d'être un moment compliqué. Tu veux m'en dire un peu plus ?",
            ],
            default  => [
                "Je t'écoute. Tu veux m'en dire un peu plus ?",
                "D'accord, je te suis. Qu'est-ce qui s'est passé pour toi aujourd'hui ?",
                "Je comprends. Tu veux qu'on continue à en parler ?",
                "Merci de partager ça avec moi. Qu'est-ce que tu aimerais me raconter ?",
            ],
        };

        return $this->pickDistinct($candidates);
    }

    /**
     * Pioche un candidat distinct du dernier fallback servi (anti-répétition).
     */
    private function pickDistinct(array $candidates): string
    {
        $pool = array_values(array_filter(
            $candidates,
            fn ($c) => $c !== $this->lastFallback
        ));
        if (empty($pool)) {
            $pool = $candidates;
        }
        return $pool[array_rand($pool)];
    }

    /**
     * Message de mode sécurité quand le filet déterministe détecte un signal rouge
     * que l'IA aurait raté (P10, P13).
     *
     * P7 + P9 (V4) :
     *  - vocabulaire 100% Maroc (parent, enseignant, surveillant, responsable de l'école, directeur).
     *  - PAS de mention « infirmière » (vocabulaire non aligné avec le système marocain).
     *  - 141 mentionné UNIQUEMENT pour 8-11 et 12-14, et avec formulation conditionnelle
     *    « si tu ne peux parler à personne tout de suite ». Pour 5-7 on évite le numéro
     *    (l'enfant ne sait pas appeler) et on oriente vers un adulte présent.
     */
    private function safetyMessage(string $ageGroup): string
    {
        return match ($ageGroup) {
            '5-7'   => "Ce que tu me dis est très important. 💛 Tu n'es pas tout seul. Va voir un grand en qui tu as confiance (papa, maman, ton enseignant, le surveillant ou le directeur) et raconte-lui maintenant.",
            '8-11'  => "Ce que tu me dis compte beaucoup. Tu n'as pas à rester seul avec ça. S'il te plaît, va parler maintenant à un adulte de confiance — un parent, un enseignant, le surveillant ou le responsable de l'école. Si tu ne peux parler à personne tout de suite, tu peux aussi appeler gratuitement le 141 au Maroc.",
            default => "Ce que tu traverses est lourd, et tu n'es pas seul. Le plus important maintenant, c'est d'en parler à un adulte de confiance — un parent, un enseignant, le responsable de l'école ou le directeur. Si tu ne peux parler à personne tout de suite, tu peux aussi appeler gratuitement le 141 au Maroc. S'il te plaît, ne reste pas seul avec ça.",
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
