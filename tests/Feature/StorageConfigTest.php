<?php

namespace Tests\Feature;

use App\Support\PrivateFile;
use App\Support\PublicFile;
use Tests\TestCase;

/**
 * Guards the public/private storage split.
 *
 * Both failures these cover are silent at runtime. A public disk with no 'url'
 * renders every image as a broken icon with no exception and nothing logged. A
 * public custom domain on the bucket that also holds submissions publishes
 * student work, and looks entirely normal from the inside.
 *
 * Neither would fail a test that only checked "does the app boot", so they get
 * checked explicitly.
 */
class StorageConfigTest extends TestCase
{
    /** The production R2 setup, as .env.production.example defines it. */
    private function useProductionDisks(array $overrides = []): void
    {
        config([
            'filesystems.disks.r2.bucket' => 'tuition-prod',
            'filesystems.disks.r2.url' => null,
            'filesystems.disks.r2_public.bucket' => 'tuition-public',
            'filesystems.disks.r2_public.url' => 'https://cdn.example.com',
            'filesystems.default' => 'r2',
            'filesystems.uploads_disk' => 'r2_public',
            ...$overrides,
        ]);
    }

    public function test_the_shipped_production_configuration_passes_the_check(): void
    {
        $this->useProductionDisks();

        $this->artisan('storage:check')->assertSuccessful();
    }

    public function test_public_urls_use_the_custom_domain_not_the_api_endpoint(): void
    {
        $this->useProductionDisks();

        $url = PublicFile::url('banners/slide.webp');

        $this->assertSame('https://cdn.example.com/banners/slide.webp', $url);
        $this->assertStringNotContainsString(
            'r2.cloudflarestorage.com',
            $url,
            'Public URLs must not point at the private S3 API endpoint — it 403s.',
        );
    }

    public function test_the_check_fails_when_the_public_disk_has_no_url(): void
    {
        $this->useProductionDisks(['filesystems.disks.r2_public.url' => null]);

        $this->artisan('storage:check')->assertFailed();
    }

    public function test_the_check_fails_when_public_and_private_share_a_bucket(): void
    {
        $this->useProductionDisks(['filesystems.disks.r2_public.bucket' => 'tuition-prod']);

        $this->artisan('storage:check')->assertFailed();
    }

    public function test_the_check_fails_when_both_disks_are_the_private_one(): void
    {
        // The pre-fix configuration: UPLOADS_DISK=r2 alongside FILESYSTEM_DISK=r2.
        $this->useProductionDisks(['filesystems.uploads_disk' => 'r2']);

        $this->artisan('storage:check')->assertFailed();
    }

    public function test_the_private_disk_is_not_publicly_addressable(): void
    {
        $this->useProductionDisks();

        $this->assertEmpty(
            config('filesystems.disks.r2.url'),
            'The private bucket must never have a public URL configured.',
        );

        $this->assertFalse(
            method_exists(PrivateFile::class, 'url'),
            'PrivateFile must not expose url() — access goes through an authorising controller.',
        );
    }

    public function test_the_two_disks_resolve_to_different_buckets(): void
    {
        $this->useProductionDisks();

        $public = config('filesystems.disks.'.PublicFile::disk().'.bucket');
        $private = config('filesystems.disks.'.PrivateFile::disk().'.bucket');

        $this->assertNotSame($public, $private);
    }

    public function test_dev_defaults_are_valid(): void
    {
        // Local disks, no cloud storage — must not report false problems.
        config(['filesystems.default' => 'local', 'filesystems.uploads_disk' => 'public']);

        $this->artisan('storage:check')->assertSuccessful();
    }
}
