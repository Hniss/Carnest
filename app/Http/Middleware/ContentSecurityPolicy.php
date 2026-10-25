<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * E-002 (test d'intrusion Nergal du 2026-10-22) — politique de sécurité du contenu stricte.
 *
 * Un nonce aléatoire par requête est posé sur Vite ET repris par Livewire (ses balises lisent
 * Vite::cspNonce()) : seuls les scripts portant ce nonce, ou servis par le site lui-même,
 * s'exécutent (plus l'évaluation d'expressions dont Livewire/Alpine ont besoin). Les ressources autorisées sont celles réellement chargées par les gabarits :
 * Google Fonts (feuille + polices), images du site et données en ligne (data:), appels
 * Livewire vers le site lui-même. Aucun cadre parent, aucune base ni cible de formulaire
 * externes.
 *
 * CSP_MODE : « report-only » (observation, violations journalisées dans
 * storage/logs/csp-violations.log), « enforce » (bloquant), « off ».
 */
class ContentSecurityPolicy
{
    public const REPORT_PATH = '/csp-report';

    public function handle(Request $request, Closure $next): Response
    {
        $mode = (string) config('carenest.csp_mode', 'report-only');

        if (! in_array($mode, ['enforce', 'report-only'], true)) {
            return $next($request);
        }

        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set(
            $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only',
            self::policy($nonce)
        );

        return $response;
    }

    public static function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            // 'unsafe-eval' : mesuré le 2026-10-24 en mode rapport seul — Livewire/Alpine évaluent les
            // expressions des attributs (x-data, wire:…) par eval ; sans lui, chaque page remonte une
            // violation « eval » depuis livewire.js et l'interface ne réagit plus. Le nonce reste la
            // barrière contre toute balise <script> injectée.
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
            'report-uri '.self::REPORT_PATH,
        ]);
    }
}
