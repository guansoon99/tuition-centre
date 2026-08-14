<?php

namespace Tests\Feature;

use App\Support\CourseMedia;
use App\Support\PrivateFile;
use App\Support\PrivateImage;
use App\Support\PublicFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Which stores may re-encode an image, and which must not.
 *
 * The line matters: student submissions are graded evidence. A photo of
 * handwritten work downscaled to IMAGE_MAX_WIDTH and re-encoded lossily may
 * stop being legible, and the student has no way to know it happened.
 *
 * Everything else — announcement images, lesson media, banners — is content
 * the school produced, where shrinking an 8 MP phone photo is a straight win.
 */
class FileCompressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(PrivateFile::disk());
        Storage::fake(PublicFile::disk());
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('phone-photo.jpg', 3200, 2400);
    }

    public function test_announcement_images_are_re_encoded_to_webp(): void
    {
        $path = PrivateImage::store($this->photo(), 'announcement-images');

        $this->assertStringEndsWith('.webp', $path);
    }

    public function test_lesson_media_is_re_encoded_to_webp(): void
    {
        $path = CourseMedia::store($this->photo(), CourseMedia::folder(1));

        $this->assertStringEndsWith('.webp', $path);
    }

    public function test_a_submission_is_stored_byte_for_byte(): void
    {
        $upload = $this->photo();
        $expected = $upload->getSize();

        $path = PrivateFile::storeAs($upload, 'submissions/1/2/3', 'homework.jpg');

        $this->assertSame('submissions/1/2/3/homework.jpg', $path);
        $this->assertSame(
            $expected,
            Storage::disk(PrivateFile::disk())->size($path),
            'A student submission was altered on the way to storage.',
        );
    }

    /**
     * The reason PrivateImage is a separate class rather than a flag flipped
     * on PrivateFile. Submissions happen to use storeAs(), which never
     * compresses — but that is which method a caller reached for, not a
     * guarantee. This asserts the guarantee.
     */
    public function test_private_file_never_re_encodes_even_via_store(): void
    {
        $upload = $this->photo();
        $expected = $upload->getSize();

        $path = PrivateFile::store($upload, 'submissions/1/2/3');

        $this->assertStringEndsNotWith('.webp', $path);
        $this->assertSame($expected, Storage::disk(PrivateFile::disk())->size($path));
    }

    /** Public assets keep compressing; this refactor must not have moved that. */
    public function test_public_uploads_still_compress(): void
    {
        $path = PublicFile::store($this->photo(), 'banner-slides');

        $this->assertStringEndsWith('.webp', $path);
    }

    /**
     * Compression applies to images only — a PDF or video passes through.
     *
     * No size assertion here: UploadedFile::fake()->create() reports a size it
     * has not actually written, so comparing it against the stored bytes
     * measures the test helper rather than the code. The image cases above can
     * compare sizes because ->image() writes real pixels.
     */
    public function test_non_image_uploads_are_left_alone(): void
    {
        $path = PrivateImage::store(
            UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf'),
            'announcement-images',
        );

        $this->assertStringEndsWith('.pdf', $path);
        $this->assertStringEndsNotWith('.webp', $path);
    }
}
