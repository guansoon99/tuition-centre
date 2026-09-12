<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The /users table: Name, Role, Active, Username, Password, Last login,
 * Created. The Password column shows the stored plaintext for student rows
 * only (generated logins, the same field the roster export uses); a staff
 * account's row stays blank even if the field is set.
 */
class UsersIndexColumnsTest extends TestCase
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

    private function index(): string
    {
        return $this->actingAs($this->admin)->get(route('users.index'))->assertOk()->getContent();
    }

    public function test_the_columns_are_in_the_agreed_order(): void
    {
        $html = $this->index();

        $positions = [];
        foreach (['Name', 'Role', 'Active', 'Username', 'Password', 'Last login', 'Created'] as $heading) {
            $pos = strpos($html, '<th class="px-4 py-3">'.$heading.'</th>');
            $this->assertNotFalse($pos, "Heading {$heading} is missing.");
            $positions[] = $pos;
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Headings are not in the agreed order.');
    }

    public function test_rows_are_in_name_order_regardless_of_case_or_creation_time(): void
    {
        // Created newest-first on purpose, so a creation-time sort would
        // reverse them; mixed case so a case-sensitive sort would misplace
        // the lowercase one.
        foreach (['Zul', 'amir', 'Chong'] as $name) {
            $u = User::factory()->create(['name' => $name]);
            $u->assignRole('student');
        }

        $html = $this->index();
        $amir = strpos($html, '>amir<');
        $chong = strpos($html, '>Chong<');
        $zul = strpos($html, '>Zul<');

        $this->assertNotFalse($amir);
        $this->assertTrue($amir < $chong && $chong < $zul, 'Expected amir, Chong, Zul in that order.');
    }

    public function test_a_students_row_shows_the_stored_password(): void
    {
        $student = User::factory()->create(['username' => 'std_one', 'plain_password' => 'Paper-Login-1']);
        $student->assignRole('student');

        $this->assertStringContainsString('Paper-Login-1', $this->index());
    }

    public function test_a_staff_row_never_shows_one_even_if_the_field_is_set(): void
    {
        $teacher = User::factory()->create(['username' => 'tch_one', 'plain_password' => 'Should-Not-Appear']);
        $teacher->assignRole('teacher');

        $html = $this->index();
        $this->assertStringContainsString('tch_one', $html);
        $this->assertStringNotContainsString('Should-Not-Appear', $html);
    }

    public function test_a_student_without_a_stored_password_shows_a_dash(): void
    {
        $student = User::factory()->create(['username' => 'std_two', 'plain_password' => null]);
        $student->assignRole('student');

        $html = $this->index();
        // The cell for this row: username, then the password cell.
        $afterUsername = substr($html, strpos($html, 'std_two'));
        $this->assertStringContainsString('—', substr($afterUsername, 0, 600));
    }
}
