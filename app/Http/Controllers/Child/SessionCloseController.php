<?php

namespace App\Http\Controllers\Child;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\ChatSession;
use App\Services\SessionCloser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * #1 (Probleme CareNest V5) — Clôture de session déclenchée à la fermeture /
 * actualisation de la fenêtre, pour qu'aucune alerte ni résumé ne soit perdu
 * quand l'enfant ne clique pas sur « J'ai fini ma session ».
 *
 * Appelé via navigator.sendBeacon() (POST JSON) depuis la vue chat sur
 * pagehide / visibilitychange→hidden. Réutilise SessionCloser (même logique
 * que le bouton de fin de session) — aucune divergence de comportement.
 *
 * Sécurité : route sous guard 'child'. On vérifie que la session appartient
 * bien à l'enfant connecté avant toute opération.
 *
 * Conformité 09-08 / RGPD : les messages reçus ne servent qu'EN TRANSIT pour
 * l'analyse IA et la résolution du niveau d'alerte. Seuls summary/zone/flags
 * sont persistés — jamais le contenu brut.
 */
class SessionCloseController extends Controller
{
    public function __invoke(Request $request, SessionCloser $closer): Response
    {
        $child = auth('child')->user();

        $data = $request->validate([
            'session_id'         => ['required', 'integer'],
            'messages'           => ['sometimes', 'array'],
            'messages.*.role'    => ['required_with:messages', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required_with:messages', 'string'],
        ]);

        $session = ChatSession::find($data['session_id']);

        // Session inconnue ou n'appartenant pas à l'enfant connecté → refus.
        if (! $session || $session->child_id !== $child->id) {
            abort(403);
        }

        // Déjà close (bouton « Fin de session » ou job idle) → rien à faire.
        if ($session->ended_at !== null) {
            return response()->noContent();
        }

        // On retire le 1er message (welcome assistant) pour aligner le contexte
        // d'analyse sur celui de ChatInterface::endSession().
        $messages = collect($data['messages'] ?? [])
            ->map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']])
            ->skipWhile(fn ($m) => $m['role'] === 'assistant')
            ->values()
            ->all();

        $closer->close(
            $session,
            $messages,
            $child,
            $session->zone ?? 'green',
            null,
            Alert::where('session_id', $session->id)->exists(),
        );

        return response()->noContent();
    }
}
