<?php

namespace Tests\Feature\Services;

use App\Models\Course;
use App\Models\User;
use App\Services\PasswordGenerator;
use App\Services\StudentImporter;
use App\Services\UsernameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    public function test_username_generator_returns_sequential_ids(): void
    {
        $g = app(UsernameGenerator::class);

        $first = $g->generateForStudent();
        $second = $g->generateForStudent();
        $third = $g->generateForStudent();

        $this->assertSame('student1', $first);
        $this->assertSame('student2', $second);
        $this->assertSame('student3', $third);
    }

    public function test_password_generator_avoids_ambiguous_chars_and_meets_length(): void
    {
        $g = new PasswordGenerator;

        for ($i = 0; $i < 25; $i++) {
            $pw = $g->generate(10);
            $this->assertSame(10, strlen($pw));
            $this->assertDoesNotMatchRegularExpression('/[0O1Il]/', $pw);
            $this->assertMatchesRegularExpression('/[A-Z]/', $pw);
            $this->assertMatchesRegularExpression('/[a-z]/', $pw);
            $this->assertMatchesRegularExpression('/\d/', $pw);
        }
    }

    public function test_student_importer_creates_users_and_enrollments(): void
    {
        $course = Course::factory()->create(['code' => 'PA-S1']);

        $importer = app(StudentImporter::class);

        $result = $importer->processRows([
            ['name' => 'Ali Bin Abu', 'phone' => '012-3456789', 'email' => '', 'course_code' => 'PA-S1', 'expires_at' => null, 'notes' => ''],
            ['name' => 'Siti Aisyah', 'phone' => '', 'email' => 'siti@x.test', 'course_code' => 'PA-S1', 'expires_at' => '2027-01-01', 'notes' => 'VIP'],
        ], dryRun: false);

        $this->assertCount(2, $result['ok']);
        $this->assertCount(0, $result['errors']);
        $this->assertCount(0, $result['skipped']);

        $this->assertSame(2, User::role('student')->count());
        $this->assertSame(2, $course->enrollments()->count());

        $this->assertArrayHasKey('username', $result['ok'][0]);
        $this->assertArrayHasKey('plain_password', $result['ok'][0]);
    }

    public function test_student_importer_skips_duplicates_by_name_and_course(): void
    {
        $course = Course::factory()->create(['code' => 'PA-S1']);

        $existing = User::factory()->create(['name' => 'Ali Bin Abu']);
        $existing->assignRole('student');
        $existing->enrolledCourses()->attach($course->id, ['enrolled_at' => now(), 'is_active' => true]);

        $importer = app(StudentImporter::class);
        $result = $importer->processRows([
            ['name' => 'Ali Bin Abu', 'course_code' => 'PA-S1'],
        ], dryRun: false);

        $this->assertCount(1, $result['skipped']);
        $this->assertCount(0, $result['ok']);
    }

    public function test_student_importer_records_errors_for_missing_data_or_unknown_course(): void
    {
        Course::factory()->create(['code' => 'KNOWN']);

        $importer = app(StudentImporter::class);
        $result = $importer->processRows([
            ['name' => '', 'course_code' => 'KNOWN'],          // missing name
            ['name' => 'Bob', 'course_code' => 'UNKNOWN'],     // bad course
            ['name' => 'Charlie', 'course_code' => 'KNOWN', 'expires_at' => 'not-a-date'],
        ], dryRun: false);

        $this->assertCount(3, $result['errors']);
        $this->assertSame(0, User::role('student')->count());
    }

    public function test_student_importer_preview_does_not_persist(): void
    {
        Course::factory()->create(['code' => 'PA-S1']);

        $importer = app(StudentImporter::class);
        $result = $importer->processRows([
            ['name' => 'Sample', 'course_code' => 'PA-S1'],
        ], dryRun: true);

        $this->assertCount(1, $result['ok']);
        $this->assertSame(0, User::role('student')->count());
        $this->assertArrayNotHasKey('username', $result['ok'][0]);
    }
}
