<?php

namespace Tests\Feature\Admin;

use App\Exports\StudentImportSampleExport;
use App\Models\User;
use App\Services\StudentImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The optional email column on the student import.
 *
 * `users.email` is UNIQUE, so an address that is already taken cannot simply
 * be handed to User::create — that throws a QueryException partway through a
 * run, after earlier rows have already been created. Every check here exists
 * to turn that crash into one reported row, and to make the *preview* say so
 * before anything is written.
 */
class StudentImportEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function importer(): StudentImporter
    {
        return app(StudentImporter::class);
    }

    private function importRows(array $rows, bool $dryRun = false): array
    {
        return $this->importer()->processRows($rows, dryRun: $dryRun);
    }

    // ---- The happy path -----------------------------------------------------

    public function test_an_email_is_imported(): void
    {
        $result = $this->importRows([
            ['name' => 'Ali Bin Ahmad', 'email' => 'ali@example.test'],
        ]);

        $this->assertCount(1, $result['ok']);
        $this->assertSame('ali@example.test', User::where('name', 'Ali Bin Ahmad')->value('email'));
    }

    public function test_a_blank_email_imports_as_null(): void
    {
        $result = $this->importRows([
            ['name' => 'No Address', 'email' => ''],
        ]);

        $this->assertCount(0, $result['errors']);
        $this->assertNull(User::where('name', 'No Address')->value('email'));
    }

    /**
     * Files written against the old template have no email column at all.
     * Rows are keyed by header, so the key is simply absent — that must not
     * be an error, or every previously-working sheet would start failing.
     */
    public function test_a_file_with_no_email_column_still_imports(): void
    {
        $result = $this->importRows([
            ['name' => 'Legacy Row', 'phone' => '0123456789', 'course_code' => ''],
        ]);

        $this->assertCount(1, $result['ok']);
        $this->assertCount(0, $result['errors']);
        $this->assertNull(User::where('name', 'Legacy Row')->value('email'));
    }

    // ---- Rejections ---------------------------------------------------------

    public function test_a_malformed_email_is_reported_and_no_user_is_made(): void
    {
        $result = $this->importRows([
            ['name' => 'Bad Address', 'email' => 'not-an-email'],
        ]);

        $this->assertCount(0, $result['ok']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Invalid email', $result['errors'][0]['reason']);
        $this->assertNull(User::where('name', 'Bad Address')->first());
    }

    public function test_an_email_already_held_by_a_user_is_reported(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $result = $this->importRows([
            ['name' => 'Late Comer', 'email' => 'taken@example.test'],
        ]);

        $this->assertCount(0, $result['ok']);
        $this->assertStringContainsString('already in use', $result['errors'][0]['reason']);
    }

    /**
     * The one that would otherwise crash the import.
     *
     * A soft-deleted user still occupies their email at the database level,
     * but is invisible to a plain Eloquent query — so a check that forgets
     * withTrashed() passes here and then dies on the INSERT.
     */
    public function test_an_email_held_by_a_soft_deleted_user_is_reported_not_thrown(): void
    {
        $gone = User::factory()->create(['email' => 'archived@example.test']);
        $gone->delete();
        $this->assertSoftDeleted('users', ['id' => $gone->id]);

        $result = $this->importRows([
            ['name' => 'Reuser', 'email' => 'archived@example.test'],
        ]);

        $this->assertCount(0, $result['ok']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('already in use', $result['errors'][0]['reason']);
    }

    public function test_the_same_email_twice_in_one_file_keeps_the_first_row(): void
    {
        $result = $this->importRows([
            ['name' => 'First Person', 'email' => 'shared@example.test'],
            ['name' => 'Second Person', 'email' => 'shared@example.test'],
        ]);

        $this->assertCount(1, $result['ok']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Duplicate email in this file', $result['errors'][0]['reason']);
        $this->assertSame('First Person', $result['ok'][0]['name']);
        $this->assertNull(User::where('name', 'Second Person')->first());
    }

    /** Addresses differing only in case are the same address. */
    public function test_duplicate_detection_ignores_case(): void
    {
        User::factory()->create(['email' => 'mixed@example.test']);

        $result = $this->importRows([
            ['name' => 'Shouty', 'email' => 'MIXED@Example.TEST'],
        ]);

        $this->assertCount(0, $result['ok']);
        $this->assertCount(1, $result['errors']);
    }

    // ---- Preview ------------------------------------------------------------

    /** The admin should learn about a bad address before anything is written. */
    public function test_the_dry_run_reports_email_problems_without_creating_anyone(): void
    {
        User::factory()->create(['email' => 'used@example.test']);
        $before = User::count();

        $result = $this->importRows([
            ['name' => 'Row One', 'email' => 'not-an-email'],
            ['name' => 'Row Two', 'email' => 'used@example.test'],
            ['name' => 'Row Three', 'email' => 'dupe@example.test'],
            ['name' => 'Row Four', 'email' => 'dupe@example.test'],
        ], dryRun: true);

        $this->assertCount(3, $result['errors'], 'Invalid, taken, and in-file duplicate.');
        $this->assertCount(1, $result['ok']);
        $this->assertSame($before, User::count(), 'A dry run must not create anyone.');
    }

    /**
     * A row dropped for a duplicate name never gets created, so it must not
     * reserve its email against a later row that will be.
     */
    public function test_a_skipped_row_does_not_reserve_its_email(): void
    {
        User::factory()->create(['name' => 'Clashing Name']);

        $result = $this->importRows([
            ['name' => 'Clashing Name', 'email' => 'free@example.test'],
            ['name' => 'Distinct Name', 'email' => 'free@example.test'],
        ]);

        $this->assertCount(1, $result['skipped']);
        $this->assertCount(0, $result['errors'], 'The address was never actually taken.');
        $this->assertSame('free@example.test', User::where('name', 'Distinct Name')->value('email'));
    }

    // ---- The sample template ------------------------------------------------

    public function test_the_sample_sheet_offers_an_email_column(): void
    {
        $headings = (new StudentImportSampleExport)->headings();

        $this->assertContains('email', $headings);

        // Every sample row must still line up with the header, or the file
        // teaches admins the wrong column order.
        foreach ((new StudentImportSampleExport)->array() as $row) {
            $this->assertCount(count($headings), $row);
        }
    }

    /**
     * The text formats are positional and had to shift when email went in at
     * C. If they drift, Excel eats the leading zero on a phone number or an
     * IC — silently, and only in the file the admin actually fills in.
     */
    public function test_the_text_formatted_columns_still_point_at_the_right_fields(): void
    {
        $headings = (new StudentImportSampleExport)->headings();
        $formats = (new StudentImportSampleExport)->columnFormats();

        foreach (['phone', 'ic_number', 'candidate_number', 'expires_at'] as $field) {
            $letter = chr(ord('A') + array_search($field, $headings, true));

            $this->assertArrayHasKey(
                $letter,
                $formats,
                "Column {$letter} holds {$field} and must be forced to text.",
            );
        }

        $this->assertArrayNotHasKey(
            chr(ord('A') + array_search('email', $headings, true)),
            $formats,
            'Email needs no text format — Excel does not mangle an address.',
        );
    }

    // ---- What the page tells the admin --------------------------------------

    public function test_the_import_page_documents_the_email_column(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('/import-students')
            ->assertOk()
            ->assertSee('Expected file format')
            ->assertSee('Email');
    }

    /**
     * The page and the sample sheet must describe the same file.
     *
     * "Expected file format" is hand-written prose listing display names, so
     * nothing links it to headings() — a column can be added to the template
     * and the importer while this list quietly keeps describing the old file.
     * Counting the entries is crude, but it is what catches that.
     */
    public function test_the_documented_format_lists_one_entry_per_sample_column(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $html = $this->actingAs($admin)->get('/import-students')->assertOk()->getContent();

        $documented = substr_count($html, '(optional)') + substr_count($html, '(required)');

        $this->assertSame(
            count((new StudentImportSampleExport)->headings()),
            $documented,
            'The page and the sample spreadsheet disagree about the columns.',
        );
    }
}
