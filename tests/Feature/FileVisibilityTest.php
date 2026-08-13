<?php

namespace Tests\Feature;

use App\Support\PrivateFile;
use App\Support\PublicFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The public/private split is a security boundary, not a style choice.
 *
 * `public/storage` is a symlink to the public disk, so anything stored there
 * is served by the web server with no auth check at all. Anything stored on
 * the private disk sits outside the web root and can only be reached through
 * a controller that authorises the caller first.
 *
 * These tests pin the boundary itself, so a future upload that reaches for
 * the wrong helper fails here rather than quietly exposing student work.
 */
class FileVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_two_stores_resolve_to_different_disks(): void
    {
        $this->assertNotSame(
            PublicFile::disk(),
            PrivateFile::disk(),
            'Public and private stores must not share a disk.'
        );
    }

    /**
     * The public disk is the one behind the /storage symlink. If the private
     * disk ever pointed at a web-served root, every material and submission
     * would become downloadable without logging in.
     */
    public function test_private_disk_is_not_the_web_served_one(): void
    {
        $publicRoot = rtrim(str_replace('\\', '/', config('filesystems.disks.'.PublicFile::disk().'.root')), '/');
        $privateRoot = rtrim(str_replace('\\', '/', config('filesystems.disks.'.PrivateFile::disk().'.root')), '/');

        $this->assertNotSame($publicRoot, $privateRoot);

        // The private root must not sit inside the public (web-exposed) root.
        $this->assertStringStartsNotWith(
            $publicRoot.'/',
            $privateRoot.'/',
            'The private disk root is inside the publicly served directory.'
        );
    }

    public function test_public_store_exposes_a_url_and_private_store_does_not(): void
    {
        $this->assertTrue(method_exists(PublicFile::class, 'url'));

        // A url() on PrivateFile would defeat the whole point — the only way
        // out of that disk should be an authorised controller.
        $this->assertFalse(
            method_exists(PrivateFile::class, 'url'),
            'PrivateFile must not expose a public URL helper.'
        );
    }

    public function test_files_land_on_the_disk_their_helper_names(): void
    {
        Storage::fake(PublicFile::disk());
        Storage::fake(PrivateFile::disk());

        $publicPath = PublicFile::store(UploadedFile::fake()->create('brochure.pdf', 10), 'banner-slides');
        $privatePath = PrivateFile::store(UploadedFile::fake()->create('homework.pdf', 10), 'submissions/1/2/3');

        Storage::disk(PublicFile::disk())->assertExists($publicPath);
        Storage::disk(PrivateFile::disk())->assertMissing($publicPath);

        Storage::disk(PrivateFile::disk())->assertExists($privatePath);
        Storage::disk(PublicFile::disk())->assertMissing($privatePath);
    }

    /**
     * Public images are re-encoded to WebP to keep phone photos small.
     * Private files must be stored byte-for-byte — a student's submitted
     * work should come back exactly as they sent it.
     */
    public function test_public_images_are_compressed_and_private_ones_are_not(): void
    {
        Storage::fake(PublicFile::disk());
        Storage::fake(PrivateFile::disk());

        $publicPath = PublicFile::store(UploadedFile::fake()->image('photo.jpg', 100, 100), 'banner-slides');
        $privatePath = PrivateFile::store(UploadedFile::fake()->image('scan.jpg', 100, 100), 'submissions/1/2/3');

        $this->assertStringEndsWith('.webp', $publicPath, 'Public images should be re-encoded to WebP.');
        $this->assertStringEndsWith('.jpg', $privatePath, 'Private files must not be re-encoded.');
    }

    /**
     * Guard against the actual mistake this refactor exists to prevent:
     * a new upload feature quietly writing sensitive files to the public
     * disk. Materials and submissions must never appear there.
     */
    public function test_no_controller_stores_materials_or_submissions_publicly(): void
    {
        $offenders = [];

        foreach (glob(app_path('Http/Controllers/*/*.php')) as $file) {
            $source = file_get_contents($file);

            foreach (['submissions', 'materials'] as $sensitive) {
                if (preg_match('/PublicFile::store\w*\([^;]*'.$sensitive.'/i', $source)) {
                    $offenders[] = basename($file).' stores "'.$sensitive.'" via PublicFile';
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }
}
