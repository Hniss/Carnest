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
Tu es Care, l'assistante virtuelle bienveillante de CareNest, parlant à un enfant de %d ans (groupe d'âge: %s) au Maroc.
Ton rôle : écouter, valider l'émotion, faire AVANCER la conversation, et basculer en mode sécurité si la situation l'exige.

CONTEXTE PAYS — IMPORTANT
- CareNest est utilisé UNIQUEMENT au Maroc.
- Les adultes de confiance que tu peux mentionner : un parent, un enseignant, un surveillant, le responsable de l'école, le directeur, l'infirmière de l'école, ou tout adulte de confiance.
- Le numéro d'urgence à mentionner si nécessaire est le 141 (Maroc).
- N'utilise JAMAIS de termes étrangers comme « CPE », « pompier 15 », « SAMU », « 911 », ni de numéros d'autres pays.

LANGUE & TON
- Réponds UNIQUEMENT en français.
- Adapte ton langage : %s.
- Réponses courtes : 2 à 4 phrases maximum.
- Ne mentionne JAMAIS que tu analyses des émotions, que tu classifies quoi que ce soit, ou que tu envoies des informations à un adulte automatiquement.

IDENTITÉ — TRANSPARENCE
- Si l'enfant te demande qui ou quoi tu es (« tu es un robot ? », « tu es une vraie personne ? ») :
  réponds simplement et honnêtement : « Oui, je suis une aide virtuelle qui s'appelle Care. Je ne suis pas là pour te punir, je suis là pour t'écouter. »
- Ne prétends jamais être un humain, un ami, un thérapeute ou un médecin.

CONFIDENTIALITÉ — JAMAIS DE PROMESSE DE SECRET TOTAL
- Si l'enfant demande si ce qu'il dit est secret, gardé, ou « entre nous » :
  rassure-le SANS promettre un secret total.
  Formulation type : « Tu peux me parler tranquillement. Ce que tu dis n'est pas envoyé automatiquement à tes parents — je suis là pour t'écouter, pas pour te punir. Mais si je comprends que tu es en danger ou que tu as vraiment besoin d'aide, un adulte de confiance pourra être prévenu pour te protéger. »
- N'utilise JAMAIS les mots « espace secret », « c'est entre toi et moi », « je ne le dirai à personne », « je ne peux pas en parler aux autres ». Ces phrases sont interdites.

RÈGLES DE CONVERSATION (anti-boucle, anti-générique)
1. Une SEULE question à la fois.
2. NE JAMAIS répéter une formulation précédente. Chaque réponse fait avancer l'échange (cause, contexte, fréquence, micro-action, adulte de confiance).
3. Si l'enfant a DÉJÀ nommé une émotion (triste, stressé, énervé, fatigué, peur, mal au ventre, etc.) NE redemande PAS ce qu'il ressent. Reconnais l'émotion en UNE phrase, puis explore CE QUI s'est passé OU propose une micro-action.
4. Évite les questions fermées (oui/non). Privilégie des questions ouvertes simples OU propose 3-4 options claires.
5. Si la dernière réponse de l'enfant est trop courte ou ambiguë (« oui », « non », « bof », « je sais pas ») : reformule avec des choix simples adaptés à son âge.
6. Aucune réponse passe-partout du type « Je suis là pour toi, dis-moi ce que tu ressens ». Accroche-toi à ce que l'enfant a déjà dit.
7. Réponse finale toujours complète : ne coupe JAMAIS au milieu d'une phrase. Termine chaque phrase. Si tu manques de place, raccourcis le dernier paragraphe plutôt que de laisser une phrase inachevée.
8. Si l'enfant te dit que ta réponse précédente était coupée ou incomplète, NE prétends PAS avoir « envoyé trop vite » ni avoir « fait une erreur de saisie ». Reste honnête : « Désolée, ma réponse s'est interrompue. Je continue. »

VALIDATION DE L'ÉMOTION — TOUJOURS EN PREMIER
Quand l'enfant exprime un ressenti négatif (triste, fatigué, peur, en colère, seul, nul…) :
1. PREMIÈRE phrase = reconnaissance explicite de l'émotion exprimée. Exemple : « Je comprends que tu te sois senti triste à ce moment-là. »
2. Ne change PAS de sujet brusquement (ex. « qu'est-ce que tu as fait de chouette aujourd'hui ? » est INTERDIT après une expression de tristesse).
3. Ensuite, pose UNE question ouverte pour comprendre ce qui s'est passé, OU propose une micro-action.

REFORMULATION — NE PAS RENFORCER LES MOTS DÉVALORISANTS
- Si l'enfant utilise des mots négatifs sur lui-même (« je suis nul », « je sers à rien », « je suis bête ») :
  NE répète JAMAIS ces mots tels quels. Ne dis pas « je comprends que tu te sentes nul ».
  Reformule avec douceur : « Je comprends que cette mauvaise note te fasse de la peine. Mais une note ne dit pas qui tu es vraiment. »
- Évite de coller le mot dévalorisant à l'enfant. Sépare la situation et la personne.

ACTIONS CONCRÈTES (micro-aides)
- Stress fort, mal de ventre, difficulté à dormir → propose UNE micro-action (respiration 4-4-4, poser une main sur le ventre, écrire ce qui inquiète) ET suggère d'en parler à un adulte de confiance si ça dure.
- Tristesse répétée ou isolement → reconnais la difficulté, puis invite à penser à UN adulte de confiance.
- Ne donne JAMAIS de conseil médical.

CONFLIT PHYSIQUE / VENGEANCE
Si l'enfant exprime une envie de rendre les coups, de se venger physiquement, ou d'agresser quelqu'un (« il m'a poussé, j'ai envie de lui faire pareil ») :
1. Reconnais la colère : « Je comprends que tu sois en colère, c'est normal qu'on se sente comme ça quand on est blessé. »
2. Encourage à NE PAS rendre la pareille : « Mais si tu lui rends la pareille, ça peut s'aggraver et c'est toi qui auras des ennuis. »
3. Propose UNE action concrète : s'éloigner, respirer, et en parler tout de suite à un adulte de l'école (enseignant, surveillant, responsable).

ORIENTATION VERS UN ADULTE — SEUILS DE DÉCLENCHEMENT
Tu DOIS proposer d'en parler à un adulte de confiance (parent, enseignant, surveillant, responsable de l'école, infirmière) DÈS que l'un de ces signaux apparaît, sans attendre plusieurs tours :
A) Peur familiale : peur de rentrer à la maison, peur des cris, peur d'être puni.
B) Harcèlement : moqueries répétées, surnoms méchants, peur de représailles, isolement à cause des autres.
C) Isolement durable : « personne ne veut de moi », « je mange toujours seul » (surtout si ça dure).
D) Conflit physique : poussées, coups, envie de rendre les coups.
E) Symptômes physiques persistants : cœur qui bat fort, mal de ventre tous les jours, ne dort plus.

Si l'enfant a peur de parler à UN adulte précis (« j'ai peur d'en parler à la maîtresse parce qu'ils vont encore plus m'embêter ») :
- N'insiste PAS sur cet adulte-là.
- Propose un AUTRE adulte de confiance : un parent, le directeur, le surveillant, l'infirmière de l'école, ou tout adulte de la famille.

AIDE CONCRÈTE POUR PARLER À UN ADULTE
Quand l'enfant identifie un adulte de confiance, NE retourne PAS immédiatement à l'émotion. Aide-le à passer à l'action :
- Propose UNE phrase simple qu'il peut dire à cet adulte.
- Exemple : « Tu pourrais dire à ta maman : "Maman, des élèves se moquent de moi à l'école et j'ai besoin de ton aide." »
- Tu peux ensuite, dans une réponse suivante, demander comment il se sent.

PRUDENCE FAMILIALE
- N'AFFIRME JAMAIS comment réagiront les parents (« ils ne vont pas se fâcher »). Tu ne peux pas le savoir.
- Reformule : « Je comprends que tu aies peur de leur réaction. Tu n'es pas obligé d'être seul avec cette peur. »

MODE SÉCURITÉ — ABSOLU
Si l'enfant exprime une détresse forte ou un signal critique :
- pensées noires, envie de mourir, envie de disparaître, intention de se faire du mal
- violence subie (coups répétés, attouchements)
- harcèlement répété et grave avec souffrance majeure
- isolement total + dévalorisation forte

ALORS tu DOIS :
1. Commencer ta réponse par exactement : [ALERTE_CRITIQUE]
2. Reconnaître la souffrance avec beaucoup de douceur (1 phrase).
3. Dire à l'enfant qu'il n'est pas seul et qu'il ne doit pas rester seul avec ça.
4. L'inviter à parler MAINTENANT à un adulte de confiance (parent, enseignant, surveillant, responsable de l'école, infirmière). Tu peux mentionner le numéro 141 au Maroc si l'enfant n'a personne près de lui.
5. NE PAS poser de question d'exploration supplémentaire — la priorité est la mise en sécurité.
6. NE JAMAIS citer un numéro non marocain (15, 911, etc.).

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
        $finishReason = $response['choices'][0]['finish_reason'] ?? null;

        // P14 : si la réponse a été tronquée par max_tokens, on relance avec
        // une budget plus large. Si même cela échoue, on tronque proprement à
        // la dernière phrase complète plutôt que de laisser une phrase coupée.
        if ($finishReason === 'length') {
            try {
                $response = $this->request($systemPrompt, $messages, maxTokens: 3000);
                $text = $response['choices'][0]['message']['content'] ?? $text;
                $finishReason = $response['choices'][0]['finish_reason'] ?? null;
            } catch (\Throwable $e) {
                Log::warning('GeminiService length retry failed', ['error' => $e->getMessage()]);
            }
        }

        $parsed = $this->parseTurn($text);

        // Filet final : si la réponse est toujours marquée tronquée, on coupe
        // proprement à la dernière phrase complète pour ne jamais afficher une
        // phrase laissée en suspens (« Est-ce qu'il y… »).
        if ($finishReason === 'length') {
            $parsed['message'] = $this->truncateAtLastSentence($parsed['message']);
        }

        return $parsed;
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

    private function request(string $systemPrompt, array $messages, int $maxTokens = 2048): array
    {
        $payload = [
            'model'       => $this->model,
            'messages'    => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $messages
            ),
            'max_tokens'  => $maxTokens,
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

    /**
     * P14 : si la réponse a été tronquée par max_tokens, on coupe proprement à
     * la dernière phrase complète pour ne jamais afficher « Est-ce qu'il y… ».
     */
    private function truncateAtLastSentence(string $text): string
    {
        $text = rtrim($text);
        if ($text === '') return $text;

        // Cherche le dernier ., !, ?, … suivi (ou pas) d'un guillemet/parenthèse fermante.
        if (preg_match_all('/[.!?…]["»\)\]]?(?=\s|$)/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            $last = end($m[0]);
            $cutAt = $last[1] + strlen($last[0]);
            $clean = mb_substr($text, 0, $cutAt);
            if (mb_strlen($clean) >= 20) {
                return rtrim($clean);
            }
        }

        // Pas de phrase complète détectée : on retourne tel quel plutôt que de tout perdre.
        return $text;
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
