<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_public_landing_renders_for_guests(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Login');
    }

    public function test_protected_routes_redirect_unauthenticated_users(): void
    {
        $this->get('/account')->assertRedirect('/login');
        $this->get('/courses/anything')->assertRedirect('/login');
    }

    public function test_admin_logs_in_and_is_redirected_home(): void
    {
        $admin = User::factory()->create(['username' => 'admin1', 'password' => 'secret123']);
        $admin->assignRole('admin');

        $this->post('/login', ['username' => 'admin1', 'password' => 'secret123'])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($admin);
        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_teacher_logs_in_and_is_redirected_home(): void
    {
        $teacher = User::factory()->create(['username' => 'teach1', 'password' => 'secret123']);
        $teacher->assignRole('teacher');

        $this->post('/login', ['username' => 'teach1', 'password' => 'secret123'])
            ->assertRedirect('/');
    }

    public function test_student_logs_in_and_is_redirected_home(): void
    {
        $student = User::factory()->create(['username' => 'STU20260001', 'password' => 'secret123']);
        $student->assignRole('student');

        $this->post('/login', ['username' => 'STU20260001', 'password' => 'secret123'])
            ->assertRedirect('/');
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['username' => 'inactive1', 'password' => 'secret123', 'is_active' => false]);
        $user->assignRole('student');

        $this->post('/login', ['username' => 'inactive1', 'password' => 'secret123'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_wrong_password_fails(): void
    {
        $user = User::factory()->create(['username' => 'someone', 'password' => 'secret123']);
        $user->assignRole('student');

        $this->post('/login', ['username' => 'someone', 'password' => 'wrong'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_throttle_locks_out_after_five_failed_attempts(): void
    {
        $user = User::factory()->create(['username' => 'target1', 'password' => 'secret123']);
        $user->assignRole('student');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'target1', 'password' => 'wrong']);
        }

        $response = $this->post('/login', ['username' => 'target1', 'password' => 'secret123']);
        $response->assertSessionHasErrorsIn('default', ['username']);

        $errors = session('errors')->getBag('default')->get('username');
        $this->assertStringContainsString('Too many login attempts', $errors[0]);
    }

    public function test_logout_invalidates_session(): void
    {
        $user = User::factory()->create(['username' => 'bye1', 'password' => 'secret123']);
        $user->assignRole('student');

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_active_middleware_kicks_out_user_deactivated_mid_session(): void
    {
        $user = User::factory()->create(['username' => 'kickme', 'password' => 'secret123']);
        $user->assignRole('student');

        $this->actingAs($user)->get('/')->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user->fresh())->get('/')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('username');
    }

    public function test_single_session_enforcement_is_wired_up(): void
    {
        // 1. AuthenticateSession middleware must be in the web group — without it,
        //    the password-hash rotation below has no effect on other sessions.
        $kernel = app(\App\Http\Kernel::class);
        $reflection = new \ReflectionClass($kernel);
        $groups = $reflection->getProperty('middlewareGroups');
        $groups->setAccessible(true);
        $web = $groups->getValue($kernel)['web'] ?? [];

        $this->assertContains(
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            $web,
            'AuthenticateSession middleware must be in the web group for single-session enforcement.'
        );

        // 2. Login must call Auth::logoutOtherDevices(), which re-hashes the password
        //    (same plaintext, new bcrypt salt). Other sessions' stored markers become
        //    stale and AuthenticateSession kicks them out on their next request.
        $user = User::factory()->create(['username' => 'shared', 'password' => 'secret123']);
        $user->assignRole('student');
        $hashBefore = $user->password;

        $this->post('/login', ['username' => 'shared', 'password' => 'secret123'])
            ->assertRedirect('/');

        $this->assertNotSame(
            $hashBefore,
            $user->fresh()->password,
            'Login should re-hash the password via logoutOtherDevices to invalidate other sessions.'
        );
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('target1|127.0.0.1');
        parent::tearDown();
    }
}
