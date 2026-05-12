<?php

namespace Tests\Feature\Admin;

use App\Exports\StudentCredentialsExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImportStudentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    public function test_import_page_renders_for_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('/import-students')->assertOk()->assertSee('Import students');
    }

    public function test_import_page_blocked_for_non_admin(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($student)->get('/import-students')->assertForbidden();
    }

    public function test_credentials_export_produces_expected_rows(): void
    {
        $rows = [
            ['name' => 'Ali', 'username' => 'student1', 'plain_password' => 'Abc12def', 'course' => 'PA-S1', 'email' => null],
            ['name' => 'Siti', 'username' => 'student2', 'plain_password' => 'Xyz34abc', 'course' => 'PA-S1', 'email' => 's@x.test'],
        ];

        $export = new StudentCredentialsExport($rows);

        $this->assertSame(['Name', 'Username', 'Password', 'Course', 'Email'], $export->headings());
        $this->assertSame(
            [
                ['Ali', 'student1', 'Abc12def', 'PA-S1', ''],
                ['Siti', 'student2', 'Xyz34abc', 'PA-S1', 's@x.test'],
            ],
            $export->array()
        );
    }
}
