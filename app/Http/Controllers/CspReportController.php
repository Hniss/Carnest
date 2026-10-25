<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * E-002 — réception des rapports de violation CSP envoyés par les navigateurs.
 * Journal dédié hors racine web (storage/logs/csp-violations-AAAA-MM-JJ.log, 14 jours).
 * Les adresses sont ramenées au schéma + hôte + chemin, sans chaîne de requête ni fragment,
 * tronquées : aucun jeton de réinitialisation ni identifiant n'entre dans le journal.
 * Plafond global (toutes IP confondues) en plus de la limite par IP de la route.
 * Répond toujours 204 : un rapport illisible ne mérite ni erreur ni information en retour.
 */
class CspReportController extends Controller
{
    public const MAX_BYTES = 8192;
    public const GLOBAL_PER_MINUTE = 120;
    public const MAX_URL_CHARS = 160;

    public function __invoke(Request $request): Response
    {
        $raw = $request->getContent();

        if ($raw === '' || strlen($raw) > self::MAX_BYTES) {
            return response()->noContent();
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return response()->noContent();
        }

        // Format « report-uri » ({"csp-report": {...}}) ou « Reporting API » ([{"body": {...}}]).
        $reports = isset($data['csp-report']) ? [$data['csp-report']] : array_column($data, 'body');

        foreach (array_slice(array_filter($reports, 'is_array'), 0, 10) as $report) {
            if (! RateLimiter::attempt('csp-report-global', self::GLOBAL_PER_MINUTE, fn () => true, 60)) {
                break;
            }

            Log::channel('csp')->info('Violation CSP', [
                'document'  => self::url(($report['document-uri'] ?? $report['documentURL'] ?? '')),
                'directive' => (string) ($report['violated-directive'] ?? $report['effectiveDirective'] ?? ''),
                'blocked'   => self::url(($report['blocked-uri'] ?? $report['blockedURL'] ?? '')),
                'source'    => self::url(($report['source-file'] ?? $report['sourceFile'] ?? '')),
                'line'      => $report['line-number'] ?? $report['lineNumber'] ?? null,
                'sample'    => mb_substr((string) ($report['script-sample'] ?? $report['sample'] ?? ''), 0, 120),
                'agent'     => mb_substr((string) $request->userAgent(), 0, 160),
            ]);
        }

        return response()->noContent();
    }

    /** Schéma + hôte + chemin, sans requête ni fragment, tronqué ; mots-clés CSP (inline, eval, data) gardés. */
    private static function url(mixed $value): string
    {
        $value = is_string($value) ? $value : '';
        $value = preg_split('/[?#]/', $value, 2)[0];
        // Jetons et identifiants portés par le CHEMIN : lien de réinitialisation, vérification
        // d'e-mail, numéros d'élève/alerte, longues chaînes aléatoires.
        $value = preg_replace('#/(reset-password|verify-email)/[^/]+(/[^/]+)?#', '/$1/{jeton}', $value);
        $value = preg_replace('#/\d+(?=/|$)#', '/{id}', $value);
        $value = preg_replace('#/[A-Za-z0-9_-]{24,}(?=/|$)#', '/{jeton}', $value);

        return mb_substr($value, 0, self::MAX_URL_CHARS);
    }
}
