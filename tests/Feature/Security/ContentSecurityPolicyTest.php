<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * E-002 (test d'intrusion Nergal du 2026-10-22, mineur) — la CSP se limitait à
 * upgrade-insecure-requests. Politique stricte avec nonce par requête, mode « rapport seul »
 * puis bloquant (CSP_MODE), violations journalisées hors racine web.
 */
class ContentSecurityPolicyTest extends TestCase
{
    private const HEADER = 'Content-Security-Policy';
    private const REPORT_ONLY = 'Content-Security-Policy-Report-Only';

    public function test_enforcing_mode_sends_a_strict_policy_with_a_nonce(): void
    {
        config(['carenest.csp_mode' => 'enforce']);

        $response = $this->get('/login')->assertOk();
        $policy = $response->headers->get(self::HEADER);

        $this->assertNotNull($policy);
        $response->assertHeaderMissing(self::REPORT_ONLY);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]{40})' 'unsafe-eval'(;|$)/", $policy, $policy);
        foreach ([
            "default-src 'self'", "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com", "img-src 'self' data:", "connect-src 'self'",
            "frame-ancestors 'none'", "base-uri 'self'", "form-action 'self'", "object-src 'none'",
            'report-uri /csp-report',
        ] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }
        $this->assertStringNotContainsString("'unsafe-inline'", explode(';', explode('script-src', $policy)[1])[0]);
    }

    public function test_the_nonce_of_the_header_is_the_one_carried_by_the_script_tags(): void
    {
        config(['carenest.csp_mode' => 'enforce']);

        $response = $this->get('/login')->assertOk();
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $response->headers->get(self::HEADER), $m);
        $nonce = $m[1];
        $html = $response->getContent();

        // Vite (app.js) et Livewire (livewire.js) portent le nonce.
        $this->assertMatchesRegularExpression('/<script[^>]*src="[^"]*build\/assets\/app-[^"]+\.js"[^>]*nonce="'.$nonce.'"/', $html);
        $this->assertMatchesRegularExpression('/<script[^>]*src="[^"]*livewire[^"]*"[^>]*nonce="'.$nonce.'"/', $html);
        // Aucune balise <script> sans nonce.
        preg_match_all('/<script\b[^>]*>/', $html, $tags);
        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$nonce.'"', $tag, $tag);
        }
    }

    public function test_the_nonce_changes_at_every_request(): void
    {
        config(['carenest.csp_mode' => 'enforce']);

        $a = $this->get('/login')->headers->get(self::HEADER);
        $b = $this->get('/login')->headers->get(self::HEADER);

        $this->assertNotSame($a, $b);
    }

    public function test_report_only_mode_uses_the_report_only_header(): void
    {
        config(['carenest.csp_mode' => 'report-only']);

        $response = $this->get('/login')->assertOk();

        $response->assertHeaderMissing(self::HEADER);
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get(self::REPORT_ONLY));
        $this->assertStringContainsString('report-uri /csp-report', $response->headers->get(self::REPORT_ONLY));
    }

    public function test_off_mode_sends_no_policy(): void
    {
        config(['carenest.csp_mode' => 'off']);

        $this->get('/login')->assertOk()->assertHeaderMissing(self::HEADER)->assertHeaderMissing(self::REPORT_ONLY);
    }

    public function test_child_login_page_also_carries_the_policy(): void
    {
        config(['carenest.csp_mode' => 'enforce']);

        $this->get('/child/login')->assertOk()->assertHeader(self::HEADER);
    }

    public function test_violation_reports_are_written_to_a_log_outside_the_web_root(): void
    {
        $path = storage_path('logs/csp-violations-test.log');
        File::delete($path);
        config(['logging.channels.csp.path' => $path]);

        $this->postJson('/csp-report', ['csp-report' => [
            'document-uri' => 'https://carenest.hy-way.org/login',
            'violated-directive' => 'script-src',
            'blocked-uri' => 'inline',
            'line-number' => 12,
        ]])->assertNoContent();

        $this->assertFileExists($path);
        $log = file_get_contents($path);
        $this->assertStringContainsString('script-src', $log);
        $this->assertStringContainsString('/login', $log);
        $this->assertStringNotContainsString('public', dirname($path));
        File::delete($path);
    }

    public function test_violation_endpoint_accepts_the_reporting_api_content_type_without_csrf(): void
    {
        $path = storage_path('logs/csp-violations-test.log');
        File::delete($path);
        config(['logging.channels.csp.path' => $path]);

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'],
            json_encode(['csp-report' => ['violated-directive' => 'font-src', 'blocked-uri' => 'https://evil.example']]))
            ->assertNoContent();

        $this->assertStringContainsString('evil.example', file_get_contents($path));
        File::delete($path);
    }

    public function test_violation_endpoint_ignores_oversized_or_non_json_bodies(): void
    {
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'pas du json')->assertNoContent();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat('x', 20000))->assertNoContent();
    }
}
