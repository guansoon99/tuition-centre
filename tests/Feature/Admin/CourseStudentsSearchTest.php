<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The search box on the course edit page's students tab. Same rule as
 * /users: a substring of the username or the name, applied server-side, so
 * it behaves the same on a course with 300 students as with 3.
 */
class CourseStudentsSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->course = Course::factory()->create(['is_active' => true]);

        foreach ([['aiman_r', 'Aiman Rashid'], ['beatrice_k', 'Beatrice Koh'], ['chong_wl', 'Chong Wei Lun']] as [$username, $name]) {
            $student = User::factory()->create(['username' => $username, 'name' => $name]);
            $student->assignRole('student');
            Enrollment::create([
                'course_id' => $this->course->id, 'user_id' => $student->id,
                'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
            ]);
        }
    }

    private function studentsTab(string $q = '')
    {
        return $this->actingAs($this->admin)
            ->get(route('courses.edit', ['course' => $this->course, 'tab' => 'students', 'q' => $q]));
    }

    public function test_without_a_search_every_student_is_listed(): void
    {
        $this->studentsTab()->assertOk()
            ->assertSee('aiman_r')->assertSee('beatrice_k')->assertSee('chong_wl')
            ->assertDontSee('of 3 match');
    }

    public function test_searching_by_username_narrows_the_list(): void
    {
        $this->studentsTab('beat')->assertOk()
            ->assertSee('beatrice_k')
            ->assertDontSee('aiman_r')->assertDontSee('chong_wl')
            ->assertSee('1 of 3 match');
    }

    public function test_searching_by_name_narrows_the_list(): void
    {
        $this->studentsTab('Wei Lun')->assertOk()
            ->assertSee('chong_wl')
            ->assertDontSee('aiman_r')->assertDontSee('beatrice_k');
    }

    public function test_no_match_says_so_rather_than_no_students_enrolled(): void
    {
        $this->studentsTab('zzz')->assertOk()
            ->assertSee('No students match')
            ->assertDontSee('No students enrolled')
            ->assertSee('0 of 3 match');
    }

    public function test_the_search_form_stays_on_the_students_tab(): void
    {
        $this->studentsTab('beat')->assertOk()
            ->assertSee('name="tab" value="students"', false)
            ->assertSee('value="beat"', false);
    }

    public function test_the_search_leaves_the_enroll_dropdown_alone(): void
    {
        // The search is for the table. A student not yet enrolled must still
        // be offered in the Enroll form whatever the box says.
        $outsider = User::factory()->create(['username' => 'dina_o', 'name' => 'Dina Ong']);
        $outsider->assignRole('student');

        $this->studentsTab('beat')->assertOk()->assertSee('dina_o');
    }
}
