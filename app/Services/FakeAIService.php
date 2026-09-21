<?php

namespace App\Services;

use App\Enums\AlertType;

/**
 * Lot 3 — faux fournisseur d'IA pour la démonstration LOCALE uniquement.
 *
 * Lié dans AppServiceProvider SEULEMENT si APP_ENV=local ET AI_FAKE=1 (jamais par défaut,
 * jamais en production). Aucun appel réseau : les réponses sont déterministes, en français,
 * une question à la fois, sans emoji, et la zone est dérivée du filet déterministe
 * (CrisisDetector) complété de quelques mots-clés simples. Sert aussi de client à
 * l'adjudicateur (rawCompletion renvoie le JSON strict attendu).
 */
class FakeAIService extends GeminiService
{
    public const MODEL = 'fake-demo';

    private const YELLOW_WORDS = ['triste', 'fatigu', 'stress', 'peur', 'inquiet', 'inquiète', 'nerveux', 'nerveuse', 'contrôle', 'controle', 'seul', 'seule'];
    private const ORANGE_WORDS = ['moque', 'moquent', 'embête', 'embêtent', 'insulte', 'frappe', 'frappé', 'tape', 'harcèle', 'harcele', 'menace', 'personne ne veut'];

    public function __construct()
    {
        parent::__construct(apiKey: 'fake', model: self::MODEL);
    }

    public function chat(array $messages, int $childAge, ?string $childGender = null, ?string $childContext = null, array $flags = []): array
    {
        $last = $this->lastUserMessage($messages);
        [$zone, $type] = $this->classify($last);

        // Zone rouge : on laisse le filet déterministe de ChatInterface servir le message de
        // sécurité officiel (D5, 2511) — is_critical reste faux, la zone rouge est conservée.
        $isCritical = false;
        $message = match ($zone) {
            'red'    => 'Ce que tu me dis compte beaucoup et je te prends au sérieux. Tu n\'as pas à porter ça tout seul. Le plus important maintenant, c\'est d\'en parler tout de suite à un adulte de confiance : un parent, un enseignant ou le responsable de l\'école. Est-ce qu\'il y a un adulte à qui tu peux parler aujourd\'hui ?',
            'orange' => 'Merci de me le dire, ce n\'est pas facile à raconter. Ce que tu vis n\'est pas normal et tu n\'y es pour rien. Est-ce qu\'un adulte de l\'école est au courant ?',
            'yellow' => 'Je comprends, ça peut peser. Tu veux me dire ce qui te fait ressentir ça en ce moment ?',
            default  => $this->greenReply($last),
        };

        return [
            'message'        => $message,
            'zone'           => $zone,
            'alert_type'     => $type,
            'is_critical'    => $isCritical,
            'low_confidence' => false,
            'tokens'         => $this->fakeTokens($messages, $message),
            'model'          => self::MODEL,
        ];
    }

    public function analyzeSession(array $messages, int $childAge, ?string $childGender = null): array
    {
        $worst = 'green';
        $type = null;
        foreach ($messages as $m) {
            if (($m['role'] ?? '') !== 'user') {
                continue;
            }
            [$z, $t] = $this->classify((string) ($m['content'] ?? ''));
            if ($this->rank($z) > $this->rank($worst)) {
                $worst = $z;
                $type = $t;
            }
        }

        $summary = match ($worst) {
            'red'    => 'Échange calme au départ, puis l\'enfant a exprimé des pensées négatives sur lui-même. Besoin d\'un adulte de confiance rapidement.',
            'orange' => 'L\'enfant décrit une situation difficile avec d\'autres personnes (moqueries ou conflit). Situation à qualifier.',
            'yellow' => 'L\'enfant exprime une inquiétude ou une fatigue passagère, sans signe de danger.',
            default  => 'Échange positif, l\'enfant parle de sa journée et d\'activités agréables.',
        };

        return [
            'summary'       => $summary,
            'zone'          => $worst,
            'alert_type'    => $type,
            'lowConfidence' => false,
            'tokens'        => $this->fakeTokens($messages, $summary),
            'model'         => self::MODEL,
        ];
    }

    public function generateCareMemory(array $messages, int $childAge): array
    {
        $text = '';
        foreach ($messages as $m) {
            $c = mb_strtolower((string) ($m['content'] ?? ''));
            if (($m['role'] ?? '') === 'user' && (str_contains($c, 'récré') || str_contains($c, 'jou') || str_contains($c, 'copin') || str_contains($c, 'sport'))) {
                $text = 'Aime jouer à la récréation avec ses camarades.';
                break;
            }
        }

        return ['memory' => $text, 'tokens' => 20, 'model' => self::MODEL];
    }

    /** Client de l'adjudicateur : JSON strict {zone, type, confirm, signals}. */
    public function rawCompletion(array $messages, float $temperature, int $maxTokens): array
    {
        $data = '';
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'user') {
                $data = (string) ($m['content'] ?? '');
            }
        }

        $worst = 'green';
        $type = null;
        foreach (explode("\n", $data) as $line) {
            if (! str_starts_with($line, 'enfant : ')) {
                continue;
            }
            [$z, $t] = $this->classify(substr($line, 9));
            if ($this->rank($z) > $this->rank($worst)) {
                $worst = $z;
                $type = $t;
            }
        }

        preg_match('/zone = (\w+)/', $data, $pz);
        $proposed = $pz[1] ?? 'green';
        $confirm = $this->rank($worst) >= $this->rank($proposed) || $this->rank($proposed) - $this->rank($worst) <= 1;

        $signals = match ($worst) {
            'red'    => ['expression d\'une envie de disparaître', 'demande implicite d\'aide'],
            'orange' => ['description de moqueries répétées'],
            'yellow' => ['inquiétude exprimée'],
            default  => [],
        };

        $json = json_encode([
            'zone'    => $worst,
            'type'    => $type ?? 'none',
            'confirm' => $confirm,
            'signals' => $signals,
        ], JSON_UNESCAPED_UNICODE);

        return ['text' => $json, 'tokens' => 30, 'model' => self::MODEL];
    }

    // ── Internes ─────────────────────────────────────────────────────────

    /** @return array{0:string,1:?string} [zone, type] */
    private function classify(string $text): array
    {
        $det = app(CrisisDetector::class)->evaluate($text, 'green');
        if ($det['matched']) {
            return [$det['zone'], $det['alert_type']];
        }

        $t = mb_strtolower($text);
        foreach (self::ORANGE_WORDS as $w) {
            if (str_contains($t, $w)) {
                return ['orange', AlertType::Harcelement->value];
            }
        }
        foreach (self::YELLOW_WORDS as $w) {
            if (str_contains($t, $w)) {
                $type = (str_contains($t, 'seul')) ? AlertType::Isolement->value : AlertType::Stress->value;
                return ['yellow', $type];
            }
        }

        return ['green', null];
    }

    private function greenReply(string $last): string
    {
        $t = mb_strtolower($last);
        if ($t === '' ) {
            return 'Je t\'écoute. Comment s\'est passée ta journée ?';
        }
        if (str_contains($t, 'récré') || str_contains($t, 'jou') || str_contains($t, 'copin') || str_contains($t, 'ami')) {
            return 'Ça a l\'air d\'avoir été un bon moment. Qu\'est-ce que tu as préféré ?';
        }
        if (str_contains($t, 'bien') || str_contains($t, 'content') || str_contains($t, 'super')) {
            return 'Ça fait plaisir à entendre. Qu\'est-ce qui t\'a rendu content aujourd\'hui ?';
        }

        return 'Merci de me le raconter. Et toi, comment tu te sens avec ça ?';
    }

    private function lastUserMessage(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return (string) ($messages[$i]['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * Volume plausible pour la démonstration du plafond journalier (D8).
     *
     * Même définition que GeminiService::conversationTokens() : uniquement le
     * CONTENU NOUVEAU du tour (réponse produite + dernier message de l'enfant),
     * jamais l'historique réémis — sinon la démonstration locale referme la
     * conversation après quelques échanges, ce que le plafond ne doit jamais faire.
     */
    private function fakeTokens(array $messages, string $reply = ''): int
    {
        $chars = mb_strlen($reply) + mb_strlen($this->lastUserMessage($messages));

        return 20 + intdiv($chars, 4);
    }

    private function rank(string $zone): int
    {
        return ['green' => 0, 'yellow' => 1, 'orange' => 2, 'red' => 3][$zone] ?? 0;
    }
}
