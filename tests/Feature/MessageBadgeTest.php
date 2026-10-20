<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The message badge polls `/api/messages/count`. It needs the signed-in
 * session, so it must live in the session-backed route group — the `api` group
 * has no session, and the badge would always answer 401.
 */
class MessageBadgeTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    public function test_a_signed_in_user_gets_the_badge_count(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $response = $this->actingAs($owner)->getJson('/api/messages/count');

        $response->assertOk();
        $response->assertJson(['ok' => true, 'count' => 0]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_signed_out_caller_gets_a_401(): void
    {
        $this->getJson('/api/messages/count')->assertUnauthorized();
    }
}
