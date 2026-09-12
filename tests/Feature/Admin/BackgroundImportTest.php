<?php

namespace Tests\Feature\Admin;

use App\Jobs\ImportStudents;
use App\Models\StudentImport;
use App\Models\User;
use App\Services\StudentImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The student import as a background job: the run creates a StudentImport
 * row and queues ImportStudents; the page shows progress while it is
 * queued/running and the result afterwards; the job records everything on
 * the row and keeps the importer's all-or-nothing transaction.
 *
 * phpunit.xml runs the sync queue, so dispatch() executes the job inline
 * unless Queue::fake() is on — which is how the queued state is tested.
 */
class BackgroundImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        Storage::fake('local');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function csv(array $names): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('students.csv', "name\n".implode("\n", $names)."\n");
    }

    /** Upload through the preview step, as the page does, so the file is stashed. */
    private function previewed(array $names): void
    {
        $this->actingAs($this->admin)
            ->post(route('import.preview'), ['file' => $this->csv($names)])
            ->assertRedirect();
    }

    public function test_running_queues_the_job_and_the_page_shows_progress(): void
    {
        Queue::fake();
        $this->previewed(['Alice', 'Bob']);

        $this->actingAs($this->admin)
            ->post(route('import.run'), ['mode' => 'skip'])
            ->assertRedirect();

        $import = StudentImport::sole();
        $this->assertSame(StudentImport::STATUS_QUEUED, $import->status);
        $this->assertSame('students.csv', $import->original_name);
        Storage::disk('local')->assertExists($import->stored_path);
        Queue::assertPushed(ImportStudents::class, fn ($job) => $job->import->is($import));

        // Nothing created yet, and the page is the progress panel.
        $this->assertSame(0, User::role('student')->count());
        $this->actingAs($this->admin)
            ->get(route('import.show'))
            ->assertOk()
            ->assertSee('Importing')
            ->assertSee('students.csv')
            // @js() in the view emits the URL JSON-escaped, slashes included.
            ->assertSee(str_replace('/', '\/', route('import.status', $import)), false)
            ->assertDontSee('Expected file format');

        $this->actingAs($this->admin)
            ->getJson(route('import.status', $import))
            ->assertOk()
            ->assertJson(['status' => 'queued', 'done' => 0, 'percent' => 0]);
    }

    public function test_the_job_imports_records_the_result_and_writes_the_sheet(): void
    {
        $this->previewed(['Alice', 'Bob', 'Cara']);

        // Sync queue: the job runs inside this request.
        $response = $this->actingAs($this->admin)->post(route('import.run'), ['mode' => 'skip']);

        $import = StudentImport::sole();
        $response->assertRedirect(route('import.show', ['import' => $import->id]));

        $this->assertSame(StudentImport::STATUS_DONE, $import->status);
        $this->assertSame(3, $import->total);
        $this->assertSame(3, $import->done);
        $this->assertCount(3, $import->result['ok']);
        $this->assertSame(3, User::role('student')->count());
        $this->assertNotNull($import->credentials_path);
        Storage::disk('local')->assertExists($import->credentials_path);
        Storage::disk('local')->assertMissing($import->stored_path, 'The upload is consumed by the job.');

        $this->actingAs($this->admin)
            ->getJson(route('import.status', $import))
            ->assertJson(['status' => 'done', 'done' => 3, 'total' => 3, 'percent' => 100, 'created' => 3]);

        // The result page, and the sheet.
        $this->actingAs($this->admin)
            ->get(route('import.show', ['import' => $import->id]))
            ->assertOk()
            ->assertSee('Import result')
            ->assertSee('Alice')
            ->assertSee(route('import.credentials', $import), false);
        $this->actingAs($this->admin)
            ->get(route('import.credentials', $import))
            ->assertOk();
    }

    public function test_a_failing_run_is_recorded_and_creates_nobody(): void
    {
        $this->previewed(['Alice', 'Bob', 'Cara']);
        $created = 0;
        User::creating(function () use (&$created) {
            if (++$created === 2) {
                throw new RuntimeException('simulated failure mid-import');
            }
        });

        $this->actingAs($this->admin)->post(route('import.run'), ['mode' => 'skip'])->assertRedirect();

        $import = StudentImport::sole();
        $this->assertSame(StudentImport::STATUS_FAILED, $import->status);
        $this->assertStringContainsString('simulated failure', $import->error);
        $this->assertSame(0, User::role('student')->count(), 'The transaction rolls the first row back.');
        Storage::disk('local')->assertMissing($import->stored_path);

        $this->actingAs($this->admin)
            ->get(route('import.show', ['import' => $import->id]))
            ->assertOk()
            ->assertSee('failed')
            ->assertSee('Nothing was created');
    }

    public function test_progress_is_reported_row_by_row(): void
    {
        $seen = [];
        app(StudentImporter::class)->processRows(
            [['name' => 'A'], ['name' => 'B'], ['name' => 'C']],
            dryRun: false,
            onProgress: function (int $done, int $total) use (&$seen) {
                $seen[] = [$done, $total];
            },
        );

        $this->assertSame([[0, 3], [1, 3], [2, 3], [3, 3]], $seen);
    }

    public function test_a_second_import_cannot_start_while_one_is_active(): void
    {
        StudentImport::create([
            'user_id' => $this->admin->id, 'original_name' => 'first.csv', 'stored_path' => 'imports/first.csv',
            'status' => StudentImport::STATUS_RUNNING,
        ]);
        $this->previewed(['Alice']);

        $this->actingAs($this->admin)
            ->post(route('import.run'), ['mode' => 'skip'])
            ->assertRedirect(route('import.show'))
            ->assertSessionHasErrors('file');

        $this->assertSame(1, StudentImport::count());
    }

    public function test_status_and_credentials_belong_to_the_admin_who_ran_the_import(): void
    {
        $this->previewed(['Alice']);
        $this->actingAs($this->admin)->post(route('import.run'), ['mode' => 'skip']);
        $import = StudentImport::sole();

        $other = User::factory()->create();
        $other->assignRole('admin');
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->actingAs($other)->getJson(route('import.status', $import))->assertForbidden();
        $this->actingAs($other)->get(route('import.credentials', $import))->assertForbidden();
    }

    public function test_the_job_will_not_run_the_same_import_twice(): void
    {
        $this->previewed(['Alice']);
        $this->actingAs($this->admin)->post(route('import.run'), ['mode' => 'skip']);
        $import = StudentImport::sole();
        $this->assertSame(StudentImport::STATUS_DONE, $import->status);

        // A redelivered or manually re-run job finds the row finished and does nothing.
        (new ImportStudents($import))->handle(app(StudentImporter::class));

        $this->assertSame(1, User::role('student')->count());
    }
}
