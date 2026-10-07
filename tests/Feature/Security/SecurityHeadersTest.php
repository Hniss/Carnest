<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/** B1 (audit sécurité du 2026-10-07) — en-têtes de durcissement HTTP sur toutes les réponses web. */
class SecurityHeadersTest extends TestCase
{
    public function test_login_page_carries_hardening_headers(): void
    {
        $response = $this->get('https://localhost/login');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $response->assertHeaderMissing('X-Powered-By');
    }

    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
    }
}
