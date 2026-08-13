<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\UsernameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Users are soft deleted: the row survives so enrollments, submissions and
 * audit history stay intact and the account can be restored. The two things
 * that must hold for that to be safe are pinned here — a deleted user cannot
 * authenticate, and username generation looks through trashed rows so it
 * never hands back a name the unique index still considers taken.
 */
class UserSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeStudent(array $attrs = []): User
    {
        $student = User::factory()->create($attrs + ['is_active' => true]);
        $student->assignRole('student');

        return $student;
    }

    public function test_bulk_delete_soft_deletes_and_keeps_the_row(): void
    {
        $student = $this->makeStudent();

        $this->actingAs($this->admin)
            ->post('/users/bulk-destroy', ['ids' => [$student->id]])
            ->assertRedirect();

        // Invisible to normal queries...
        $this->assertNull(User::find($student->id));
        // ...but still there, and restorable.
        $this->assertNotNull(User::withTrashed()->find($student->id));
        $this->assertNotNull(User::withTrashed()->find($student->id)->deleted_at);
    }

    /**
     * The security-critical one. Laravel's EloquentUserProvider builds its
     * lookup off the model's query builder, so the SoftDeletes global scope
     * excludes trashed users — but that's an implicit guarantee worth
     * asserting rather than assuming.
     */
    public function test_soft_deleted_user_cannot_log_in(): void
    {
        $student = $this->makeStudent([
            'username' => 'gone_student',
            'password' => 'secret-pw',
        ]);

        // Sanity check: the credentials work before deletion.
        $this->post('/login', ['username' => 'gone_student', 'password' => 'secret-pw'])
            ->assertRedirect('/');
        $this->post('/logout');

        $student->delete();

        $this->post('/login', ['username' => 'gone_student', 'password' => 'secret-pw'])
            ->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_soft_deleted_user_drops_out_of_admin_and_course_listings(): void
    {
        $course = Course::factory()->create();
        $student = $this->makeStudent(['username' => 'vanishing_stu']);

        Enrollment::create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT,
            'is_active' => true,
            'enrolled_at' => now(),
        ]);

        $this->actingAs($this->admin)->get('/users')->assertSee('vanishing_stu');

        $student->delete();

        $this->actingAs($this->admin)->get('/users')->assertDontSee('vanishing_stu');
        $this->assertSame(0, $course->students()->count());
    }

    /**
     * The enrollment row outlives the student (nothing cascades on a soft
     * delete), so the course edit page must not choke dereferencing a null
     * user. Regression guard for a hard 500.
     */
    public function test_course_edit_page_survives_a_soft_deleted_enrollee(): void
    {
        $course = Course::factory()->create();
        $student = $this->makeStudent();

        Enrollment::create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT,
            'is_active' => true,
            'enrolled_at' => now(),
        ]);

        $student->delete();

        // The enrollment row is still there — that's the trap.
        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id]);

        $this->actingAs($this->admin)
            ->get("/courses/{$course->slug}/edit?tab=students")
            ->assertOk();
    }

    /**
     * A soft-deleted user still holds their username at the DB level — the
     * unique index knows nothing about deleted_at.
     *
     * The collision needs a username sitting AHEAD of the counter, which is
     * exactly what happens when an admin hand-creates "student3" while the
     * counter is still at 0. Delete that user, let the counter walk up to 3,
     * and without withTrashed() the generator hands back "student3" — which
     * then dies on insert with a unique violation mid-import.
     */
    public function test_username_generator_skips_soft_deleted_usernames(): void
    {
        $generator = app(UsernameGenerator::class);

        // Hand-created ahead of the counter, then deleted.
        $this->makeStudent(['username' => 'student3'])->delete();

        // Walk the counter up to (and past) the collision point.
        $issued = [
            $generator->generateForStudent(),
            $generator->generateForStudent(),
            $generator->generateForStudent(),
        ];

        $this->assertNotContains('student3', $issued, 'Generator handed back a soft-deleted username.');

        // Every name it issued must be genuinely insertable.
        foreach ($issued as $username) {
            $user = $this->makeStudent(['username' => $username]);
            $this->assertDatabaseHas('users', ['id' => $user->id, 'username' => $username]);
        }
    }

    public function test_soft_deleted_user_keeps_their_enrollment_and_history(): void
    {
        $course = Course::factory()->create();
        $student = $this->makeStudent();

        Enrollment::create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT,
            'is_active' => true,
            'enrolled_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post('/users/bulk-destroy', ['ids' => [$student->id]]);

        // The whole point of soft delete — nothing cascaded away.
        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id]);
    }

    public function test_restoring_a_user_brings_them_fully_back(): void
    {
        $student = $this->makeStudent(['username' => 'comeback_kid', 'password' => 'secret-pw']);

        $student->delete();
        User::withTrashed()->find($student->id)->restore();

        $this->assertNotNull(User::find($student->id));

        $this->post('/login', ['username' => 'comeback_kid', 'password' => 'secret-pw'])
            ->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_admins_and_self_are_still_skipped(): void
    {
        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole('admin');

        $this->actingAs($this->admin)
            ->post('/users/bulk-destroy', ['ids' => [$otherAdmin->id, $this->admin->id]]);

        $this->assertNotNull(User::find($otherAdmin->id));
        $this->assertNotNull(User::find($this->admin->id));
    }
}
