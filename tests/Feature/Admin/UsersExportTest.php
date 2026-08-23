<?php

namespace Tests\Feature\Admin;

use App\Exports\UsersExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The users spreadsheet.
 *
 * `headings()` and `map()` are two positional lists that have to stay in step.
 * Adding a column to one and not the other raises no error at all — every
 * later value simply slides under the wrong heading, so passwords appear in
 * the IC column and nobody notices until an admin acts on the wrong data.
 * The alignment test below is the guard against that.
 */
class UsersExportTest extends TestCase
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

    private function rowFor(User $user): array
    {
        return (new UsersExport(User::query()))->map($user->fresh()->load('roles'));
    }

    private function columnIndex(string $heading): int
    {
        $headings = (new UsersExport(User::query()))->headings();
        $index = array_search($heading, $headings, true);

        $this->assertNotFalse($index, "No '{$heading}' column in the export.");

        return $index;
    }

    // ---- Structure ----------------------------------------------------------

    /** The one that catches a column added to headings() but not map(). */
    public function test_every_heading_has_a_value_beneath_it(): void
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');

        $this->assertCount(
            count((new UsersExport(User::query()))->headings()),
            $this->rowFor($user),
            'headings() and map() have drifted out of alignment.',
        );
    }

    public function test_the_sheet_has_an_email_column(): void
    {
        $this->assertContains('Email', (new UsersExport(User::query()))->headings());
    }

    // ---- Email --------------------------------------------------------------

    public function test_the_email_is_exported(): void
    {
        $user = User::factory()->create(['email' => 'exported@example.test']);
        $user->assignRole('teacher');

        $this->assertSame(
            'exported@example.test',
            $this->rowFor($user)[$this->columnIndex('Email')],
        );
    }

    /** The field is optional, so the cell is simply empty. */
    public function test_a_user_without_an_email_exports_a_blank_cell(): void
    {
        $user = User::factory()->create(['email' => null]);
        $user->assignRole('student');

        $this->assertNull($this->rowFor($user)[$this->columnIndex('Email')]);
    }

    /**
     * Email sits beside phone, not on top of it.
     *
     * A positional slip would put the address in a neighbouring column while
     * every assertion about the value alone still passed.
     */
    public function test_the_neighbouring_columns_still_hold_their_own_values(): void
    {
        $user = User::factory()->create([
            'email' => 'neighbour@example.test',
            'phone' => '012-3456789',
            'ic_number' => 'IC-777',
        ]);
        $user->assignRole('teacher');

        $row = $this->rowFor($user);

        $this->assertSame('012-3456789', $row[$this->columnIndex('Phone')]);
        $this->assertSame('neighbour@example.test', $row[$this->columnIndex('Email')]);
        $this->assertSame('IC-777', $row[$this->columnIndex('IC Number')]);
    }

    // ---- What must not leak -------------------------------------------------

    /**
     * Adding a column must not disturb the rule that only students carry a
     * readable password. This is the export's one real secret.
     */
    public function test_only_students_export_a_readable_password(): void
    {
        $student = User::factory()->create(['plain_password' => 'student-secret']);
        $student->assignRole('student');

        $teacher = User::factory()->create(['plain_password' => 'teacher-secret']);
        $teacher->assignRole('teacher');

        $passwordColumn = $this->columnIndex('Password');

        $this->assertSame('student-secret', $this->rowFor($student)[$passwordColumn]);
        $this->assertNull(
            $this->rowFor($teacher)[$passwordColumn],
            'A non-student password must never reach the sheet.',
        );
    }

    // ---- The route ----------------------------------------------------------

    public function test_an_admin_can_download_the_sheet(): void
    {
        User::factory()->create(['email' => 'insheet@example.test'])->assignRole('student');

        $this->actingAs($this->admin)->get('/users/export')
            ->assertOk()
            ->assertHeader(
                'content-type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );
    }

    public function test_a_student_cannot_download_the_sheet(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($student)->get('/users/export')->assertForbidden();
    }
}
