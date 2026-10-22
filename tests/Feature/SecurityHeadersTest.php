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
    }

    public function test_the_map_allows_exactly_the_google_maps_frame_host(): void
    {
        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        // The map is a Google Maps <iframe>: that host is the only frame the
        // site may load.
        $this->assertStringContainsString("frame-src 'self' https://www.google.com", $csp);

        // The framed document is Google's own — it sends its own CSP — so no
        // script, image or connection host is needed for it.
        $this->assertStringNotContainsString('maps.googleapis.com', $csp);
        $this->assertStringNotContainsString('maps.gstatic.com', $csp);

        // The OpenStreetMap tile host is gone with the tile map: the page
        // loads no external image at all.
        $this->assertStringNotContainsString('tile.openstreetmap.org', $csp);

        preg_match('/img-src ([^;]+)/', $csp, $matches);
        $sources = array_map('trim', explode(' ', $matches[1]));

        $external = array_values(array_filter(
            $sources,
            static fn (string $source): bool => str_contains($source, '://'),
        ));

        $this->assertSame([], $external);
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
