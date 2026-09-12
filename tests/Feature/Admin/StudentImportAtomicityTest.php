<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\StudentImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A student import is all rows or none.
 *
 * On 2026-09-11 an 800-row import hit PHP's 30-second limit at about row
 * 400, seven times over. Each run kept the rows it had reached, so
 * re-running the file created the same people again under new usernames:
 * 2,791 accounts, 399 names duplicated. The run now happens inside one
 * transaction, so a failure of the run itself leaves nothing behind.
 */
class StudentImportAtomicityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function rows(int $n): array
    {
        return array_map(fn ($i) => ['name' => "Student {$i}"], range(1, $n));
    }

    public function test_a_run_that_dies_partway_creates_nobody(): void
    {
        // The third creation blows up, the way the timeout did mid-loop.
        $created = 0;
        User::creating(function () use (&$created) {
            if (++$created === 3) {
                throw new RuntimeException('simulated failure mid-import');
            }
        });

        try {
            app(StudentImporter::class)->processRows($this->rows(5), dryRun: false);
            $this->fail('The failure should have propagated.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated failure mid-import', $e->getMessage());
        }

        $this->assertSame(3, $created, 'The third creation is the one that failed.');
        $this->assertSame(0, User::count(), 'A half-imported file must leave no rows behind.');
    }

    public function test_a_run_that_finishes_creates_everyone(): void
    {
        $result = app(StudentImporter::class)->processRows($this->rows(5), dryRun: false);

        $this->assertCount(5, $result['ok']);
        $this->assertSame(5, User::count());
    }

    public function test_a_bad_row_still_costs_only_that_row(): void
    {
        $rows = $this->rows(3);
        $rows[1]['course_code'] = 'NO-SUCH-COURSE';

        $result = app(StudentImporter::class)->processRows($rows, dryRun: false);

        $this->assertCount(2, $result['ok']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(2, User::count(), 'Row-level problems are reported, not fatal.');
    }

    public function test_a_dry_run_touches_nothing(): void
    {
        $result = app(StudentImporter::class)->processRows($this->rows(3), dryRun: true);

        $this->assertCount(3, $result['ok']);
        $this->assertSame(0, User::count());
    }
}
