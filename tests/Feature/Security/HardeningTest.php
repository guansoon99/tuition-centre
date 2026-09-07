<?php

namespace Tests\Feature\Security;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Three small guards from a security pass.
 *
 * Each closed a gap that nothing errored on: a one-character password saved
 * fine, an admin could quietly strip their own admin role, and a link
 * material would accept any URL scheme a browser might act on. All three were
 * findings rather than bugs — the code did what it said, it just said too
 * little — so the tests here are what stops them drifting back.
 */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true, 'username' => 'admin1']);
        $this->admin->assignRole('admin');
    }

    private function userPayload(array $overrides = []): array
    {
        return array_merge([
            'username' => 'newuser',
            'name' => 'New User',
            'role' => 'student',
            'is_active' => '1',
        ], $overrides);
    }

    // ---- 4. Admin-set passwords have a floor --------------------------------

    /**
     * Same minimum as the self-service change. Without it an admin could set
     * "1", and a student who never changes their password keeps it for good.
     */
    public function test_an_admin_cannot_create_a_user_with_a_short_password(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), $this->userPayload([
                'password' => 'abc',
                'password_confirmation' => 'abc',
            ]))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['username' => 'newuser']);
    }

    public function test_an_admin_cannot_reset_a_user_to_a_short_password(): void
    {
        $student = User::factory()->create(['is_active' => true, 'username' => 'stu1']);
        $student->assignRole('student');
        $before = $student->password;

        $this->actingAs($this->admin)
            ->patch(route('users.update', $student), $this->userPayload([
                'username' => 'stu1',
                'password' => 'abc',
                'password_confirmation' => 'abc',
            ]))
            ->assertSessionHasErrors('password');

        $this->assertSame($before, $student->fresh()->password, 'The hash must not have changed.');
    }

    public function test_an_eight_character_password_is_accepted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), $this->userPayload([
                'password' => 'abcd1234',
                'password_confirmation' => 'abcd1234',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['username' => 'newuser']);
    }

    // ---- 5. No self-demotion ------------------------------------------------

    /**
     * destroy() already refuses self-deactivation. This is the same lockout
     * by a different door: change your own role to student and, if you were
     * the only admin, nobody is left who can put it back.
     */
    public function test_an_admin_cannot_remove_their_own_admin_role(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('users.update', $this->admin), $this->userPayload([
                'username' => 'admin1',
                'role' => 'student',
            ]))
            ->assertSessionHasErrors('role');

        $this->assertTrue($this->admin->fresh()->hasRole('admin'));
    }

    /** Editing yourself is otherwise fine — only the role is protected. */
    public function test_an_admin_can_still_edit_their_own_details(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('users.update', $this->admin), $this->userPayload([
                'username' => 'admin1',
                'name' => 'Renamed Admin',
                'role' => 'admin',
            ]))
            ->assertSessionHasNoErrors();

        $fresh = $this->admin->fresh();

        $this->assertSame('Renamed Admin', $fresh->name);
        $this->assertTrue($fresh->hasRole('admin'));
    }

    /** Demoting a *different* admin is allowed; the guard is about self. */
    public function test_an_admin_can_demote_another_admin(): void
    {
        $other = User::factory()->create(['is_active' => true, 'username' => 'admin2']);
        $other->assignRole('admin');

        $this->actingAs($this->admin)
            ->patch(route('users.update', $other), $this->userPayload([
                'username' => 'admin2',
                'role' => 'student',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($other->fresh()->hasRole('admin'));
    }

    // ---- 6. External links are http or https only ---------------------------

    private function teacherWithSection(): array
    {
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web'])
            ->givePermissionTo('sections.manage');

        $course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);

        $teacher = User::factory()->create(['is_active' => true]);
        $teacher->assignRole('teacher');
        Enrollment::create([
            'course_id' => $course->id, 'user_id' => $teacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        return [$teacher, $section];
    }

    public static function rejectedSchemes(): array
    {
        return [
            'ftp' => ['ftp://example.com/file'],
            'file' => ['file:///etc/passwd'],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
        ];
    }

    /**
     * The `url` rule alone accepts any registered scheme. A link material is
     * followed with a redirect, so the scheme is whatever the browser gets
     * handed — http and https are the only two that should be.
     */
    #[DataProvider('rejectedSchemes')]
    public function test_a_link_material_refuses_non_web_schemes(string $url): void
    {
        [$teacher, $section] = $this->teacherWithSection();

        $this->actingAs($teacher)
            ->post(route('materials.store', $section), [
                'title' => 'Bad link',
                'type' => Material::TYPE_EXTERNAL_LINK,
                'external_url' => $url,
            ])
            ->assertSessionHasErrors('external_url');

        $this->assertDatabaseMissing('materials', ['title' => 'Bad link']);
    }

    public function test_a_link_material_accepts_https(): void
    {
        [$teacher, $section] = $this->teacherWithSection();

        $this->actingAs($teacher)
            ->post(route('materials.store', $section), [
                'title' => 'Good link',
                'type' => Material::TYPE_EXTERNAL_LINK,
                'external_url' => 'https://example.com/notes',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('materials', ['title' => 'Good link', 'external_url' => 'https://example.com/notes']);
    }
}
