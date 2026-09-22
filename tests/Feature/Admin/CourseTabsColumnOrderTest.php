<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The column order on a course's Students and Teachers tabs.
 *
 * Students: Name, Active, Username, Password, Login count, From, Ends,
 * Last accessed. Teachers: Name, Active, From, Ends, Last accessed, with
 * no username column at all.
 */
class CourseTabsColumnOrderTest extends TestCase
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
    }

    private function enrol(string $siteRole, string $courseRole, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->assignRole($siteRole);
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $user->id,
            'role_on_course' => $courseRole, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        return $user;
    }

    private function tab(string $tab): string
    {
        return $this->actingAs($this->admin)
            ->get(route('courses.edit', ['course' => $this->course, 'tab' => $tab]))
            ->assertOk()
            ->getContent();
    }

    /** @param  list<string>  $headings */
    private function assertHeadingsInOrder(array $headings, string $html): void
    {
        $positions = [];
        foreach ($headings as $heading) {
            $pos = strpos($html, '<th class="px-4 py-3">'.$heading.'</th>');
            $this->assertNotFalse($pos, "Heading {$heading} is missing.");
            $positions[] = $pos;
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Headings are out of order: '.implode(' > ', $headings));
    }

    public function test_the_students_tab_columns(): void
    {
        $this->enrol('student', Enrollment::ROLE_STUDENT, [
            'username' => 'stu_one', 'name' => 'Student One', 'plain_password' => 'abd123', 'login_count' => 4,
        ]);

        $html = $this->tab('students');

        $this->assertHeadingsInOrder(['Name', 'Active', 'Username', 'Password', 'Login count', 'From', 'Ends', 'Last accessed'], $html);

        // The row carries the password and the count.
        $row = substr($html, strpos($html, 'Student One'), 4000);
        $this->assertStringContainsString('abd123', $row);
        $this->assertStringContainsString('data-login-count>4<', $row);
        // Name comes before the username in the row, as in the header.
        $this->assertLessThan(strpos($row, 'stu_one'), 0);
    }

    public function test_the_teachers_tab_columns_carry_no_username(): void
    {
        $this->enrol('teacher', Enrollment::ROLE_TEACHER, ['username' => 'tea_one', 'name' => 'Teacher One']);

        $html = $this->tab('teachers');

        $this->assertHeadingsInOrder(['Name', 'Active', 'From', 'Ends', 'Last accessed'], $html);
        $this->assertStringNotContainsString('<th class="px-4 py-3">Username</th>', $html);

        $table = substr($html, strpos($html, 'Teacher One') - 2000, 6000);
        $this->assertStringNotContainsString('>tea_one<', $table, 'No username cell on the teachers tab.');
    }
}
