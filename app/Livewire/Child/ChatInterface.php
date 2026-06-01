<?php
namespace App\Livewire\Child;

use App\Models\Alert;
use App\Models\ChatSession;
use App\Services\AIService;
use App\Services\ChildContextBuilder;
use App\Services\CrisisDetector;
use App\Services\SessionCloser;
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

    /**
     * #7 (V5) — Bloc mémoire inter-sessions injecté dans le prompt système IA.
     * Construit une seule fois au mount à partir des données déjà persistées
     * (résumés, zones, alertes — jamais de message brut). null = 1er passage.
     */
    public ?string $childContext = null;

    public function mount(): void
    {
        $child = Auth::guard('child')->user();

        // #7 — On charge la mémoire de l'enfant AVANT d'ouvrir la session courante,
        // pour ne compter que les sessions réellement antérieures.
        $this->childContext = app(ChildContextBuilder::class)->build($child);

        $welcome = $this->getWelcome($child->age_group, $child->name, $this->childContext !== null);
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
            $aiResult = app(AIService::class)->chat($aiMessages, $child->age, $child->gender ?? null, $this->childContext);
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
            // #3 (V5) : si l'enfant a juste répondu un mot court/ambigu et qu'aucun
            // signal n'a fait monter la zone, on relance avec des choix simples
            // plutôt qu'un fallback générique — jamais d'escalade émotionnelle.
            if ($mergedZone === 'green' && $lastUser && $this->isShortAmbiguous($lastUser['content'])) {
                $reply = $this->shortAmbiguousRelaunch($child->age_group);
            } else {
                $reply = $this->buildFallback($mergedZone, $this->consecutiveFailures);
            }
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
        // #5 (V5) : le champ est ré-activé une fois la réponse arrivée → on rend
        // le focus à l'enfant pour qu'il puisse écrire sans recliquer.
        $this->dispatch('focus-input');

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

        // V5 : logique de clôture mutualisée avec le beacon de fermeture de fenêtre
        // (SessionCloser) — analyse IA, pire zone conservée, alerte idempotente,
        // recalcul score/statut synchrone. Welcome retiré (slice(1)) du contexte.
        $aiMessages = collect($this->messages)->slice(1)->values()->toArray();

        $result = app(SessionCloser::class)->close(
            $session,
            $aiMessages,
            $child,
            $this->currentZone,
            $this->currentAlertType,
            $this->alertCreated,
        );
        $this->alertCreated = $result['alert_created'];

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

    /**
     * #7 (V5) — Welcome personnalisé par prénom, et chaleureux si l'enfant est
     * déjà venu ($returning). On reste SIMPLE et bienveillant — c'est l'IA, via
     * le bloc mémoire du prompt, qui assure la continuité fine de l'échange.
     */
    private function getWelcome(string $ageGroup, ?string $name = null, bool $returning = false): string
    {
        $first = $name ? trim(explode(' ', trim($name))[0]) : null;

        if ($returning) {
            return match ($ageGroup) {
                '5-7'   => $first ? "Coucou {$first} ! 😊 Je suis contente de te revoir ! Comment tu vas aujourd'hui ?" : "Coucou ! 😊 Contente de te revoir ! Comment tu vas aujourd'hui ?",
                '8-11'  => $first ? "Hey {$first} ! Contente de te revoir 😊 Comment s'est passée ta journée ?" : "Hey ! Contente de te revoir 😊 Comment s'est passée ta journée ?",
                default => $first ? "Re-bonjour {$first}. Je suis là pour toi. Comment tu te sens aujourd'hui ?" : "Re-bonjour. Je suis là pour toi. Comment tu te sens aujourd'hui ?",
            };
        }

        return match ($ageGroup) {
            '5-7'   => $first ? "Salut {$first} ! 😊 Moi c'est Care ! Comment tu vas aujourd'hui ?" : "Salut ! 😊 Moi c'est Care ! Comment tu vas aujourd'hui ?",
            '8-11'  => $first ? "Hey {$first} ! Contente de te voir 😊 Tu peux me dire comment s'est passée ta journée ?" : "Hey ! Contente de te voir 😊 Tu peux me dire comment s'est passée ta journée ?",
            default => $first ? "Bonjour {$first} ! Je suis là pour toi. Comment tu te sens en ce moment ?" : "Bonjour ! Je suis là pour toi. Comment tu te sens en ce moment ?",
        };
    }

    /**
     * #3 (V5) — Détecte une réponse très courte / ambiguë (« oui », « non »,
     * « bof », « rien », « ok », « je sais pas ») qui ne doit JAMAIS être
     * interprétée comme un signal de détresse ni faire monter la zone.
     */
    private function isShortAmbiguous(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));
        // Retire ponctuation de fin et espaces multiples.
        $normalized = preg_replace('/[\s\.\!\?,;]+/u', ' ', $normalized);
        $normalized = trim($normalized);

        $tokens = [
            'oui', 'non', 'bof', 'rien', 'ok', 'okay', 'nan', 'ouais',
            'je sais pas', 'jsp', 'jsais pas', 'sais pas', 'aucune idee',
            'aucune idée', 'peut etre', 'peut-être', 'mouais', 'ca va', 'ça va',
        ];

        return in_array($normalized, $tokens, true);
    }

    /**
     * #3 (V5) — Relance neutre à choix simples, adaptée à l'âge, quand l'IA
     * échoue sur une réponse courte/ambiguë. Reste LÉGER, pas de dramatisation.
     */
    private function shortAmbiguousRelaunch(string $ageGroup): string
    {
        return match ($ageGroup) {
            '5-7'   => "Pas de souci 🙂 Tu veux qu'on parle de l'école, de tes copains, ou d'un dessin animé que tu aimes ?",
            '8-11'  => "D'accord 🙂 Tu préfères me parler de l'école, de tes copains, ou de ce que tu as fait aujourd'hui ?",
            default => "Pas de problème. Tu préfères qu'on parle de l'école, de tes amis, ou d'autre chose qui t'occupe en ce moment ?",
        };
    }

    public function render()
    {
        return view('livewire.child.chat-interface');
    }
}
