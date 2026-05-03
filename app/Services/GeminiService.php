<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService implements AIService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/openai';

    private const ALERT_TYPES = ['harcelement', 'detresse', 'stress', 'tristesse', 'danger', 'isolement'];

    private const SYSTEM_TEMPLATE = <<<'PROMPT'
Tu es Care, l'assistant IA bienveillant de CareNest pour un enfant de %d ans (groupe d'âge: %s).
Ton rôle : écouter avec douceur, faire AVANCER la conversation et, si la situation l'exige, basculer en mode sécurité.

LANGUE & TON
- Réponds UNIQUEMENT en français.
- Adapte ton langage : %s.
- Réponses courtes : 2 à 4 phrases maximum.
- Ne mentionne JAMAIS que tu analyses des émotions ou que tu classifies quoi que ce soit.

RÈGLES DE CONVERSATION (anti-boucle, anti-générique)
1. Une SEULE question à la fois.
2. NE JAMAIS répéter une formulation précédente. Chaque réponse doit faire avancer l'échange (cause, contexte, fréquence, micro-action).
3. Si l'enfant a DÉJÀ nommé une émotion (triste, stressé, énervé, fatigué, peur, mal au ventre, etc.) NE redemande PAS ce qu'il ressent. Reconnais l'émotion en UNE phrase, puis explore CE QUI s'est passé OU propose une micro-action.
4. Évite les questions fermées (oui/non). Privilégie des questions ouvertes simples OU propose 3-4 options claires :
   « Tu te sens plutôt triste, fatigué, énervé ou stressé ? »
5. Si la dernière réponse de l'enfant est trop courte ou ambiguë (ex. « oui », « non », « bof », « je sais pas ») : NE continue PAS comme si c'était clair. Reformule avec des choix simples adaptés à son âge.
6. Aucune réponse passe-partout du type « Je suis là pour toi, dis-moi ce que tu ressens ». Si l'enfant a déjà parlé de son ressenti, accroche-toi à ce qu'il a dit.

ACTIONS CONCRÈTES (micro-aides)
- Stress fort, mal de ventre, difficulté à dormir → propose UNE micro-action concrète (ex. respiration 4-4-4, poser une main sur le ventre, écrire ce qui inquiète) ET suggère d'en parler à un adulte de confiance si ça dure.
- Tristesse répétée ou isolement → reconnais la difficulté et invite l'enfant à penser à UNE personne de confiance avec qui en parler.
- Ne donne JAMAIS de conseil médical.

PRUDENCE FAMILIALE
- N'AFFIRME JAMAIS comment réagiront les parents ou les adultes (« ils ne vont pas se fâcher »). Tu ne peux pas le savoir.
- Reformule prudemment : « Je comprends que tu aies peur de leur réaction. Tu n'es pas obligé d'être seul avec cette peur. »

DÉTECTION DES SIGNAUX DE RISQUE
Pose des questions ciblées (fréquence, lieu, sentiment de sécurité, adulte au courant) si tu détectes :
- Moqueries / harcèlement (« on se moque de moi », « ils me tapent », « ils m'embêtent »)
- Isolement (« je suis tout seul », « personne ne me parle »)
- Peur d'un adulte de la famille
- Dévalorisation de soi (« je suis nul », « je sers à rien »)

MODE SÉCURITÉ — ABSOLU
Si l'enfant exprime une détresse forte ou un signal critique :
- pensées noires, envie de mourir, intention de se faire du mal
- violence subie (coups, attouchements)
- harcèlement répété et grave
- isolement total + dévalorisation forte

ALORS tu DOIS :
1. Commencer ta réponse par exactement : [ALERTE_CRITIQUE]
2. Reconnaître la souffrance avec beaucoup de douceur (1 phrase).
3. Dire à l'enfant qu'il n'est pas seul et qu'il ne doit pas rester seul avec ça.
4. L'inviter à parler MAINTENANT à un adulte de confiance (parent, prof, infirmière, pompier 15 ou 141 au Maroc).
5. NE PAS poser de question d'exploration supplémentaire — la priorité est la mise en sécurité.

PROTOCOLE DE FIN DE RÉPONSE — OBLIGATOIRE
À la fin de CHAQUE réponse, ajoute toujours, dans cet ordre, sur des lignes séparées :
ALERT_TYPE: <none|harcelement|detresse|stress|tristesse|danger|isolement>
ZONE: <green|yellow|orange|red>

Règles de classification (à utiliser en interne, ne jamais expliquer à l'enfant) :
- green  : calme, neutre, anodin
- yellow : stress modéré, fatigue, contrariété passagère
- orange : tristesse répétée, isolement, dévalorisation, moqueries non graves, peur familiale
- red    : détresse forte, harcèlement grave, danger, intention de se faire du mal — DOIT être précédé de [ALERTE_CRITIQUE]

Le niveau ne redescend JAMAIS sans signal explicite de l'enfant : si la conversation passe à orange, ne reviens pas à green au tour suivant sans raison.
PROMPT;

    private const ANALYSIS_PROMPT = <<<'PROMPT'
Analyse l'ENSEMBLE de la conversation ci-dessus et produis :
1. Un résumé bienveillant en 2-3 phrases (pour l'administrateur de l'école, jamais affiché à l'enfant). Mentionne le ressenti dominant et tout signal de risque éventuel.
2. Le type d'alerte le plus pertinent s'il y en a un.
3. La zone émotionnelle finale (en gardant le pire niveau atteint pendant la session si la fin redescend artificiellement).

Format de réponse OBLIGATOIRE :
SUMMARY: <ton résumé ici, sur une ou plusieurs lignes>
ALERT_TYPE: <none|harcelement|detresse|stress|tristesse|danger|isolement>
ZONE: <green|yellow|orange|red>
PROMPT;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
    ) {}

    /**
     * @return array{message:string, zone:string, alert_type:?string, is_critical:bool, low_confidence:bool}
     */
    public function chat(array $messages, int $childAge): array
    {
        $systemPrompt = $this->buildSystemPrompt($childAge);
        $response = $this->request($systemPrompt, $messages);

        $text = $response['choices'][0]['message']['content'] ?? '';

        return $this->parseTurn($text);
    }

    public function analyzeSession(array $messages, int $childAge): array
    {
        $systemPrompt = $this->buildSystemPrompt($childAge);

        $analysisMessages = array_merge($messages, [
            ['role' => 'user', 'content' => self::ANALYSIS_PROMPT],
        ]);

        $response = $this->request($systemPrompt, $analysisMessages);
        $text = $response['choices'][0]['message']['content'] ?? '';

        return $this->parseAnalysis($text);
    }

    private function buildSystemPrompt(int $age): string
    {
        $group = match (true) {
            $age <= 7  => '5-7',
            $age <= 11 => '8-11',
            default    => '12-14',
        };

        $langStyle = match ($group) {
            '5-7'   => 'très simple, émojis bienveillants, phrases très courtes',
            '8-11'  => 'amical, naturel, quelques émojis',
            default => "respectueux, mature, peu d'émojis",
        };

        return sprintf(self::SYSTEM_TEMPLATE, $age, $group, $langStyle);
    }

    private const MAX_ATTEMPTS = 3;
    private const RETRY_STATUSES = [429, 500, 502, 503, 504];

    private function request(string $systemPrompt, array $messages): array
    {
        $payload = [
            'model'       => $this->model,
            'messages'    => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $messages
            ),
            'max_tokens'  => 1200,
            'temperature' => 0.7,
        ];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $response = $this->client()->post('/chat/completions', $payload);

            if ($response->successful()) {
                return $response->json();
            }

            $status = $response->status();
            $shouldRetry = in_array($status, self::RETRY_STATUSES, true) && $attempt < self::MAX_ATTEMPTS;

            if (! $shouldRetry) {
                Log::error('GeminiService HTTP error', [
                    'status'   => $status,
                    'attempts' => $attempt,
                ]);
                throw new \RuntimeException('Gemini API error: ' . $status);
            }

            usleep($attempt * 800 * 1000);
        }

        throw new \RuntimeException('Gemini API error: retries exhausted');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->apiKey)
            ->timeout(30)
            ->acceptJson();
    }

    /**
     * Parse une réponse de tour : extrait [ALERTE_CRITIQUE], ALERT_TYPE, ZONE et nettoie le message.
     *
     * @return array{message:string, zone:string, alert_type:?string, is_critical:bool, low_confidence:bool}
     */
    private function parseTurn(string $text): array
    {
        $isCritical = false;
        if (preg_match('/\[ALERTE_CRITIQUE\]/i', $text)) {
            $isCritical = true;
            $text = preg_replace('/\[ALERTE_CRITIQUE\]/i', '', $text);
        }

        $alertType = null;
        if (preg_match('/^\s*ALERT_TYPE\s*:\s*([a-z_]+)\s*$/mi', $text, $m)) {
            $candidate = strtolower(trim($m[1]));
            if ($candidate !== 'none' && in_array($candidate, self::ALERT_TYPES, true)) {
                $alertType = $candidate;
            }
            $text = preg_replace('/^\s*ALERT_TYPE\s*:.*$/mi', '', $text);
        }

        $zone = null;
        if (preg_match('/^\s*ZONE\s*:\s*(green|yellow|orange|red)\s*$/mi', $text, $m)) {
            $zone = strtolower($m[1]);
            $text = preg_replace('/^\s*ZONE\s*:.*$/mi', '', $text);
        }

        $lowConfidence = $zone === null;
        if ($zone === null) {
            $zone = 'green';
        }
        if ($isCritical) {
            $zone = 'red';
            $alertType = $alertType ?? 'detresse';
        }

        $message = trim(preg_replace("/[\r\n]{2,}/", "\n\n", $text));

        return [
            'message'        => $message,
            'zone'           => $zone,
            'alert_type'     => $alertType,
            'is_critical'    => $isCritical,
            'low_confidence' => $lowConfidence,
        ];
    }

    private function parseAnalysis(string $text): array
    {
        $summary = '';
        if (preg_match('/SUMMARY:\s*(.+?)(?=\s*(?:ALERT_TYPE:|ZONE:)|$)/si', $text, $m)) {
            $summary = trim($m[1]);
        }

        $alertType = null;
        if (preg_match('/ALERT_TYPE:\s*([a-z_]+)/i', $text, $m)) {
            $candidate = strtolower(trim($m[1]));
            if ($candidate !== 'none' && in_array($candidate, self::ALERT_TYPES, true)) {
                $alertType = $candidate;
            }
        }

        $zone = 'green';
        $lowConfidence = false;

        if (preg_match('/ZONE:\s*(green|yellow|orange|red)/i', $text, $m)) {
            $zone = strtolower($m[1]);
        } else {
            $lowConfidence = true;
        }

        if (empty($summary)) {
            $lowConfidence = true;
            $summary = 'Session sans signal émotionnel clair.';
        }

        return [
            'summary'       => $summary,
            'zone'          => $zone,
            'alert_type'    => $alertType,
            'lowConfidence' => $lowConfidence,
        ];
    }
}
