<?php

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Support\CourseMedia;
use App\Support\PrivateFile;
use App\Support\PublicFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

/**
 * Students may submit Word and PowerPoint files, not just PDFs and images.
 *
 * The documents built here are real OOXML packages rather than fixtures with
 * the right extension, because the interesting behaviour is entirely in what
 * the bytes sniff as. Two cases matter and both occur with genuine documents:
 *
 *  - a small .docx, which libmagic identifies outright;
 *  - a .docx with a few KB of anything before word/document.xml, which
 *    libmagic reports as application/zip no matter how much of it is read.
 *
 * A change that only handled the first would look completely correct in
 * testing and reject real coursework in production.
 */
class OfficeSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    private User $student;

    private Material $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        Storage::fake(PrivateFile::disk());
        Storage::fake(PublicFile::disk());

        $course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
        $this->assignment = Material::factory()->create([
            'section_id' => $section->id,
            'type' => Material::TYPE_ASSIGNMENT,
            'is_published' => true,
            'due_date' => now()->addWeek(),
            'max_files' => 5,
            'max_file_size_mb' => 50,
        ]);

        $this->student = User::factory()->create(['is_active' => true]);
        $this->student->assignRole('student');
        Enrollment::create([
            'course_id' => $course->id, 'user_id' => $this->student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    /**
     * A real OOXML package. $leadingBlob pushes the word/ or ppt/ entry further
     * into the archive, which is what defeats libmagic's search.
     */
    private function ooxml(string $kind, int $leadingBlob = 0): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ooxml').'.zip';
        $main = $kind === 'docx' ? 'word/document.xml' : 'ppt/presentation.xml';
        $type = $kind === 'docx' ? self::DOCX : self::PPTX;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Override PartName="/'.$main.'" ContentType="'.$type.'.main+xml"/></Types>'
        );
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships/>');

        if ($leadingBlob > 0) {
            // Stored, not deflated — compressible padding would collapse and
            // leave the marker inside the search window after all.
            $zip->addFromString('docProps/thumbnail.jpeg', random_bytes($leadingBlob));
            $zip->setCompressionName('docProps/thumbnail.jpeg', ZipArchive::CM_STORE);
        }

        $zip->addFromString($main, '<?xml version="1.0"?><root/>');
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function prefix(): string
    {
        return CourseMedia::assignmentFolder(
            $this->assignment->section->course_id,
            $this->assignment->id,
            $this->student->id,
        );
    }

    private function storeAs(string $ext, string $bytes): string
    {
        $key = $this->prefix().'/'.Str::uuid().'.'.$ext;
        Storage::disk(PrivateFile::disk())->put($key, $bytes);

        return $key;
    }

    private function register(string $key, string $name)
    {
        return $this->actingAs($this->student)
            ->postJson(route('submissions.register', $this->assignment), [
                'key' => $key, 'original_name' => $name,
            ]);
    }

    private function proxiedUpload(string $name, string $bytes)
    {
        return $this->actingAs($this->student)
            ->post(route('submissions.upload', $this->assignment), [
                'files' => [UploadedFile::fake()->createWithContent($name, $bytes)],
            ]);
    }

    /**
     * Point the app at an S3-shaped disk so presign() can actually sign.
     *
     * No network is involved: SigV4 is a local computation over the request
     * and the credentials, so dummy values are enough to exercise the route.
     * The default test disk cannot presign at all, which is why the rest of
     * the suite only ever asserts presign's rejections.
     */
    private function usePresignableDisk(): void
    {
        config([
            'filesystems.disks.r2test' => [
                'driver' => 's3',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'region' => 'auto',
                'bucket' => 'test-bucket',
                'endpoint' => 'https://example.r2.cloudflarestorage.com',
                'use_path_style_endpoint' => false,
                'throw' => true,
            ],
            'filesystems.default' => 'r2test',
        ]);
    }

    /** Guards the premise of every test below: these really are different cases. */
    public function test_the_two_fixtures_sniff_differently(): void
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        $this->assertSame(
            self::DOCX,
            $finfo->buffer($this->ooxml('docx')),
            'A small .docx should identify itself.',
        );
        $this->assertSame(
            'application/zip',
            $finfo->buffer($this->ooxml('docx', 12000)),
            'A padded .docx should fall back to zip — otherwise these tests prove nothing.',
        );
    }

    public function test_presign_accepts_word_and_powerpoint(): void
    {
        $this->usePresignableDisk();
        $types = [self::DOCX, self::PPTX, 'application/msword', 'application/vnd.ms-powerpoint'];

        foreach ($types as $type) {
            $this->actingAs($this->student)
                ->postJson(route('submissions.presign', $this->assignment), [
                    'size' => 2048, 'content_type' => $type,
                ])
                ->assertOk()
                ->assertJsonStructure(['key', 'url']);
        }
    }

    public function test_the_presigned_key_gets_the_office_extension(): void
    {
        $this->usePresignableDisk();

        $key = $this->actingAs($this->student)
            ->postJson(route('submissions.presign', $this->assignment), [
                'size' => 2048, 'content_type' => self::PPTX,
            ])->json('key');

        $this->assertStringEndsWith('.pptx', $key);
    }

    public function test_register_accepts_a_word_document(): void
    {
        $key = $this->storeAs('docx', $this->ooxml('docx'));

        $this->register($key, 'Essay.docx')->assertOk();

        $this->assertSame(self::DOCX, SubmissionFile::firstOrFail()->mime_type);
    }

    /** The case a naive fix misses: a real .docx that only sniffs as a zip. */
    public function test_register_accepts_a_word_document_that_sniffs_as_zip(): void
    {
        $key = $this->storeAs('docx', $this->ooxml('docx', 12000));

        $this->register($key, 'Big Essay.docx')->assertOk();

        $this->assertSame(self::DOCX, SubmissionFile::firstOrFail()->mime_type);
    }

    public function test_register_accepts_a_powerpoint_that_sniffs_as_zip(): void
    {
        $key = $this->storeAs('pptx', $this->ooxml('pptx', 12000));

        $this->register($key, 'Slides.pptx')->assertOk();

        $this->assertSame(self::PPTX, SubmissionFile::firstOrFail()->mime_type);
    }

    /**
     * Accepting zip-as-Office must not become accepting zip-as-anything. The
     * extension on the key is server-generated from a whitelisted declared
     * type, so this combination cannot arise from an honest client.
     */
    public function test_register_rejects_an_archive_that_is_not_named_as_office(): void
    {
        $key = $this->storeAs('pdf', $this->ooxml('docx', 12000));

        $this->register($key, 'sneaky.pdf')->assertStatus(422);

        $this->assertSame(0, SubmissionFile::count());
        Storage::disk(PrivateFile::disk())->assertMissing($key);
    }

    /** Widening to Office types must not have widened anything else. */
    public function test_register_still_rejects_a_script_named_as_a_document(): void
    {
        $key = $this->storeAs('docx', '<?php system($_GET["c"]); ?>');

        $this->register($key, 'evil.docx')->assertStatus(422);

        $this->assertSame(0, SubmissionFile::count());
        Storage::disk(PrivateFile::disk())->assertMissing($key);
    }

    public function test_the_proxied_path_accepts_a_word_document(): void
    {
        $this->proxiedUpload('Essay.docx', $this->ooxml('docx'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $file = SubmissionFile::firstOrFail();
        $this->assertSame(self::DOCX, $file->mime_type);
        $this->assertStringEndsWith('.docx', $file->file_path);
        $this->assertSame('Essay.docx', $file->original_name);
    }

    public function test_the_proxied_path_accepts_a_word_document_that_sniffs_as_zip(): void
    {
        $this->proxiedUpload('Big.docx', $this->ooxml('docx', 12000))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(self::DOCX, SubmissionFile::firstOrFail()->mime_type);
    }

    /** A plain archive is still not coursework. */
    public function test_the_proxied_path_rejects_a_zip(): void
    {
        $this->proxiedUpload('stuff.zip', $this->ooxml('docx', 12000))
            ->assertSessionHasErrors('files');

        $this->assertSame(0, SubmissionFile::count());
    }

    public function test_the_picker_offers_the_office_extensions(): void
    {
        $this->actingAs($this->student)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->assertSee('.docx', false)
            ->assertSee('.pptx', false);
    }
}
