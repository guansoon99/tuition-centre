<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCrudTest extends TestCase
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

    public function test_non_admin_blocked_from_manage_routes(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($student)->get('/users')->assertForbidden();
        $this->actingAs($student)->get('/courses')->assertForbidden();
        $this->actingAs($student)->get('/access-logs')->assertForbidden();
    }

    public function test_admin_can_load_user_list_and_filter_by_role(): void
    {
        User::factory()->create(['username' => 'find_me_teach'])->assignRole('teacher');
        User::factory()->create(['username' => 'find_me_stu'])->assignRole('student');

        $this->actingAs($this->admin)->get('/users?role=teacher')
            ->assertOk()
            ->assertSee('find_me_teach')
            ->assertDontSee('find_me_stu');
    }

    public function test_admin_can_create_user_and_role_is_assigned(): void
    {
        $response = $this->actingAs($this->admin)->post('/users', [
            'username' => 'new_teacher',
            'name' => 'New Teacher',
            'email' => 'new@x.test',
            'role' => 'teacher',
            'password' => 'longpassword',
            'password_confirmation' => 'longpassword',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/users');
        $created = User::where('username', 'new_teacher')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('teacher'));
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $this->actingAs($this->admin)
            ->delete('/users/'.$this->admin->id)
            ->assertSessionHasErrors('user');

        $this->assertTrue($this->admin->fresh()->is_active);
    }

    public function test_admin_can_deactivate_and_reactivate_a_user(): void
    {
        $student = User::factory()->create(['is_active' => true]);
        $student->assignRole('student');

        $this->actingAs($this->admin)
            ->delete('/users/'.$student->id)
            ->assertRedirect('/users');
        $this->assertFalse($student->fresh()->is_active);

        $this->actingAs($this->admin)
            ->post('/users/'.$student->id.'/activate')
            ->assertRedirect('/users');
        $this->assertTrue($student->fresh()->is_active);
    }

    public function test_admin_can_create_and_edit_course(): void
    {
        $this->actingAs($this->admin)->post('/courses', [
            'code' => 'NEW-1',
            'slug' => 'new-course',
            'name' => 'New Course',
            'is_active' => '1',
        ])->assertRedirect();

        $course = Course::where('code', 'NEW-1')->first();
        $this->assertNotNull($course);

        $this->actingAs($this->admin)->patch('/courses/'.$course->slug, [
            'code' => 'NEW-1',
            'slug' => 'new-course',
            'name' => 'Renamed Course',
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertSame('Renamed Course', $course->fresh()->name);
    }

    public function test_admin_can_assign_teacher_to_course(): void
    {
        $course = Course::factory()->create();
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');

        $this->actingAs($this->admin)
            ->post('/courses/'.$course->slug.'/teachers', ['user_id' => $teacher->id])
            ->assertRedirect();

        $this->assertTrue($course->teachers()->where('users.id', $teacher->id)->exists());
    }

    public function test_admin_can_enroll_student_in_course(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($this->admin)
            ->post('/courses/'.$course->slug.'/enrollments', [
                'user_id' => $student->id,
                'expires_at' => '2027-12-31',
            ])
            ->assertRedirect();

        $this->assertTrue($course->students()->where('users.id', $student->id)->exists());
    }

    public function test_assigning_non_teacher_user_returns_422(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($this->admin)
            ->post('/courses/'.$course->slug.'/teachers', ['user_id' => $student->id])
            ->assertStatus(422);
    }

    public function test_access_logs_page_loads_for_admin(): void
    {
        $this->actingAs($this->admin)->get('/access-logs')
            ->assertOk()
            ->assertSee('Access logs');
    }
}
