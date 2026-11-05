<?php

namespace Tests\Feature\Admin;

use App\Models\StudentImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Download Students to Fix": the rows an import could not use, with every
 * column they came in with, ready to correct and upload again.
 */
class ImportErrorDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private const CSV = "name,phone,email,ic_number,candidate_number,course_code,expires_at\n"
        ."Good One,0123456789,good@example.com,,,,\n"
        ."Bad Email,0198765432,not-an-email,900101011234,BA001,,2027-01-31\n"
        .",0111111111,noname@example.com,,,,\n"
        ."Bad Course,,,,,NOPE,\n";

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

    private function upload(string $csv = self::CSV): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('students.csv', $csv);
    }

    /** @return array<int, array<int, mixed>> the sheet's rows, heading first */
    private function sheet($response): array
    {
        $copy = tempnam(sys_get_temp_dir(), 'fix').'.xlsx';
        copy($response->baseResponse->getFile()->getPathname(), $copy);
        $rows = Excel::toArray(new \stdClass, new UploadedFile($copy, 'sheet.xlsx', null, null, true))[0];
        @unlink($copy);

        return $rows;
    }

    public function test_the_preview_offers_the_rows_with_errors_in_full(): void
    {
        $this->actingAs($this->admin)->post(route('import.preview'), ['file' => $this->upload()]);

        $this->get(route('import.show'))
            ->assertOk()
            ->assertSee(route('import.preview-errors'), false)
            ->assertSee('Download Students to Fix (3)');

        $response = $this->get(route('import.preview-errors'))->assertOk();
        $this->assertStringContainsString('students_to_fix_', $response->headers->get('content-disposition'));

        $rows = $this->sheet($response);
        $this->assertSame(
            ['name', 'phone', 'email', 'ic_number', 'candidate_number', 'course_code', 'expires_at', 'error', 'original_line'],
            $rows[0],
        );
        $this->assertCount(4, $rows, 'Heading plus the three bad rows; the good row stays out.');
        $this->assertSame('Bad Email', $rows[1][0]);
        $this->assertSame('0198765432', $rows[1][1], 'The leading zero survives.');
        $this->assertSame('900101011234', $rows[1][3], 'A long IC number stays text.');
        $this->assertSame('2027-01-31', $rows[1][6]);
        $this->assertSame("Invalid email 'not-an-email'.", $rows[1][7]);
        $this->assertSame('3', $rows[1][8]);
        $this->assertSame('0111111111', $rows[2][1], 'A row with no name still comes back with what it has.');
    }

    public function test_a_finished_import_offers_the_same_download(): void
    {
        $this->actingAs($this->admin)->post(route('import.run'), ['file' => $this->upload()]);
        $import = StudentImport::firstOrFail();
        $this->assertCount(1, $import->result['ok']);

        $this->get(route('import.show', ['import' => $import->id]))
            ->assertOk()
            ->assertSee(route('import.errors', $import), false);

        $rows = $this->sheet($this->get(route('import.errors', $import))->assertOk());
        $this->assertCount(4, $rows);
        $this->assertSame('Bad Course', $rows[3][0]);
        $this->assertSame('NOPE', $rows[3][5]);
    }

    public function test_the_downloaded_file_can_be_uploaded_again(): void
    {
        $this->actingAs($this->admin)->post(route('import.preview'), ['file' => $this->upload()]);
        $copy = tempnam(sys_get_temp_dir(), 'fix').'.xlsx';
        copy($this->get(route('import.preview-errors'))->baseResponse->getFile()->getPathname(), $copy);

        $this->post(route('import.preview'), ['file' => new UploadedFile($copy, 'fixed.xlsx', null, null, true)])
            ->assertRedirect();

        // Unfixed, the same rows fail for the same reasons: the extra columns
        // changed nothing and the importer read every column back.
        $this->get(route('import.show'))
            ->assertSee('Invalid email &#039;not-an-email&#039;.', false)
            ->assertSee('Course code &#039;NOPE&#039; does not exist.', false)
            ->assertSee('Download Students to Fix (3)');

        @unlink($copy);
    }

    public function test_a_cell_starting_with_equals_comes_back_as_plain_text(): void
    {
        $this->actingAs($this->admin)->post(route('import.preview'), [
            'file' => $this->upload("name,email\n\"=1+1\",bad\n"),
        ]);

        $rows = $this->sheet($this->get(route('import.preview-errors'))->assertOk());
        $this->assertSame('=1+1', $rows[1][0]);
    }

    public function test_no_button_without_errors(): void
    {
        $this->actingAs($this->admin)->post(route('import.preview'), ['file' => $this->upload("name\nFine Person\n")]);

        $this->get(route('import.show'))->assertOk()->assertDontSee('Download Students to Fix');
    }

    public function test_cancelling_clears_the_preview_download(): void
    {
        $this->actingAs($this->admin)->post(route('import.preview'), ['file' => $this->upload()]);
        $this->post(route('import.cancel'));

        $this->get(route('import.preview-errors'))->assertRedirect(route('import.show'));
    }

    public function test_an_import_from_before_rows_were_kept_still_downloads_its_names(): void
    {
        $import = StudentImport::create([
            'user_id' => $this->admin->id, 'original_name' => 'old.csv', 'stored_path' => 'x', 'mode' => 'skip',
            'status' => StudentImport::STATUS_DONE,
            'result' => ['ok' => [], 'skipped' => [], 'errors' => [['line' => 5, 'name' => 'Old Row', 'reason' => 'Nope.']]],
        ]);

        $rows = $this->sheet($this->actingAs($this->admin)->get(route('import.errors', $import))->assertOk());
        $this->assertSame('Old Row', $rows[1][0]);
        $this->assertSame('Nope.', $rows[1][7]);
    }

    public function test_the_download_belongs_to_the_admin_who_ran_the_import(): void
    {
        $import = StudentImport::create([
            'user_id' => $this->admin->id, 'original_name' => 'a.csv', 'stored_path' => 'x', 'mode' => 'skip',
            'status' => StudentImport::STATUS_DONE,
            'result' => ['errors' => [['line' => 2, 'name' => 'A', 'reason' => 'B']]],
        ]);
        $other = User::factory()->create();
        $other->assignRole('admin');

        $this->actingAs($other)->get(route('import.errors', $import))->assertForbidden();
    }

    public function test_a_student_cannot_download_anything(): void
    {
        $import = StudentImport::create([
            'user_id' => $this->admin->id, 'original_name' => 'a.csv', 'stored_path' => 'x', 'mode' => 'skip',
            'status' => StudentImport::STATUS_DONE,
            'result' => ['errors' => [['line' => 2, 'name' => 'A', 'reason' => 'B']]],
        ]);
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($student)->get(route('import.errors', $import))->assertForbidden();
        $this->actingAs($student)->get(route('import.preview-errors'))->assertForbidden();
    }
}
