<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The eye on the login page's password field. Alpine does the toggling,
 * which PHPUnit cannot run (a Node harness does), so this pins the markup
 * the harness and the browser both rely on.
 */
class LoginPasswordEyeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_carries_the_toggle(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        // Still a password field for a browser without JavaScript.
        $this->assertStringContainsString('type="password" :type="show ? \'text\' : \'password\'" name="password"', $html);
        // The button must never submit the form.
        $this->assertStringContainsString('<button type="button" @click="show = ! show"', $html);
        $this->assertStringContainsString("show ? 'Hide password' : 'Show password'", $html);
    }
}
