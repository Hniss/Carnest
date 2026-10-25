<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * E-002 — réception des rapports de violation CSP envoyés par les navigateurs.
 * Journal dédié hors racine web (storage/logs/csp-violations.log). Aucune donnée
 * personnelle n'est demandée : seul le rapport du navigateur est consigné, borné en taille.
 * Répond toujours 204 : un rapport illisible ne mérite ni erreur ni information en retour.
 */
class CspReportController extends Controller
{
    public const MAX_BYTES = 8192;

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
            Log::channel('csp')->info('Violation CSP', [
                'document'  => (string) ($report['document-uri'] ?? $report['documentURL'] ?? ''),
                'directive' => (string) ($report['violated-directive'] ?? $report['effectiveDirective'] ?? ''),
                'blocked'   => (string) ($report['blocked-uri'] ?? $report['blockedURL'] ?? ''),
                'source'    => (string) ($report['source-file'] ?? $report['sourceFile'] ?? ''),
                'line'      => $report['line-number'] ?? $report['lineNumber'] ?? null,
                'sample'    => mb_substr((string) ($report['script-sample'] ?? $report['sample'] ?? ''), 0, 120),
                'agent'     => mb_substr((string) $request->userAgent(), 0, 160),
            ]);
        }

        return response()->noContent();
    }
}
