<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Security headers (next.config.ts securityHeaders → SecurityHeaders middleware).
 * The exact header set is asserted, including the dev-only CSP allowances and
 * the production-only HSTS header.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_core_security_headers_are_present_on_every_response(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_the_content_security_policy_has_the_expected_directives(): void
    {
        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString('https://tile.openstreetmap.org', $csp);
        $this->assertStringContainsString('https://maps.googleapis.com', $csp);
    }

    public function test_headers_are_also_applied_to_error_responses(): void
    {
        $this->get('/login')->assertNotFound()->assertHeader('X-Frame-Options', 'DENY');
    }

    // ---- direct middleware tests for the environment-dependent branches -----

    private function runMiddleware(): Response
    {
        $middleware = new SecurityHeaders;

        return $middleware->handle(Request::create('/'), fn () => response('ok'));
    }

    public function test_development_allows_unsafe_eval_and_websockets(): void
    {
        $this->assertNotSame('production', config('app.env'));

        $csp = (string) $this->runMiddleware()->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'unsafe-eval'", $csp);
        $this->assertStringContainsString('ws: wss:', $csp);
    }

    public function test_development_omits_hsts(): void
    {
        $this->assertNull($this->runMiddleware()->headers->get('Strict-Transport-Security'));
    }

    public function test_production_omits_unsafe_eval_and_websockets(): void
    {
        config(['app.env' => 'production']);

        $csp = (string) $this->runMiddleware()->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        $this->assertStringNotContainsString('ws:', $csp);
    }

    public function test_production_adds_hsts(): void
    {
        config(['app.env' => 'production']);

        $this->assertSame(
            'max-age=63072000; includeSubDomains',
            $this->runMiddleware()->headers->get('Strict-Transport-Security'),
        );
    }
}
