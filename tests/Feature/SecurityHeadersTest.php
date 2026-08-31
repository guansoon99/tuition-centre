<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Response headers the browser uses to constrain the page.
 *
 * Registered globally rather than in the `web` group so downloads carry
 * nosniff as well — those hand back whatever was uploaded, which is exactly
 * where content sniffing is worth denying.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_carries_the_headers(): void
    {
        $response = $this->get('/login')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_they_survive_a_redirect_response(): void
    {
        // An unauthenticated hit on a protected route redirects to /login;
        // the headers belong on that response too.
        $this->get('/account')
            ->assertRedirect('/login')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_they_are_present_for_a_signed_in_user(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->create(['is_active' => true]);
        $student->assignRole('student');

        $this->actingAs($student)->get('/')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    /**
     * HSTS is HTTPS-only.
     *
     * Over plain HTTP browsers ignore it anyway, and the guard stops a
     * locally served HTTP page from pinning the browser to a scheme the dev
     * box does not serve.
     */
    public function test_hsts_is_absent_over_plain_http(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_over_https(): void
    {
        $response = $this->get('https://localhost/login')->assertOk();

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    /**
     * No CSP is shipped on purpose — see the middleware for why a policy that
     * kept this app working would block almost nothing. This asserts the
     * decision rather than the absence of an oversight.
     */
    public function test_no_content_security_policy_is_claimed(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeaderMissing('Content-Security-Policy');
    }
}
