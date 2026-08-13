<?php

namespace App\Console\Commands;

use App\Support\PrivateFile;
use App\Support\PublicFile;
use Illuminate\Console\Command;

/**
 * Validates the storage configuration before it can break anything.
 *
 * Every failure below is silent at runtime — the app boots, pages render, and
 * the damage is either missing images or student work published to the
 * internet. Neither raises an exception, so nothing tells you. This does.
 */
class CheckStorageConfig extends Command
{
    protected $signature = 'storage:check';

    protected $description = 'Verify the public/private storage disks are configured safely';

    public function handle(): int
    {
        $publicDisk = PublicFile::disk();
        $privateDisk = PrivateFile::disk();

        $public = config('filesystems.disks.'.$publicDisk, []);
        $private = config('filesystems.disks.'.$privateDisk, []);

        $this->line("public uploads disk : <info>{$publicDisk}</info> (".($public['driver'] ?? '?').')');
        $this->line("private files disk  : <info>{$privateDisk}</info> (".($private['driver'] ?? '?').')');
        $this->newLine();

        $problems = [];

        // The blocker this command exists for. An s3 disk with no 'url' builds
        // URLs from the API endpoint, which requires SigV4 — so every banner,
        // announcement image and video 403s, silently.
        if (($public['driver'] ?? null) === 's3' && empty($public['url'])) {
            $problems[] = "Public disk [{$publicDisk}] has no 'url'. Its URLs will point at the "
                .'private S3 API endpoint and 403. Set R2_PUBLIC_URL to the custom domain.';
        }

        // The one that matters more. A public custom domain on the bucket that
        // also holds submissions publishes every student's work.
        if ($publicDisk !== $privateDisk
            && ($public['driver'] ?? null) === 's3'
            && ($private['driver'] ?? null) === 's3'
            && ! empty($public['bucket'])
            && $public['bucket'] === $private['bucket']
        ) {
            $problems[] = "Public and private disks both use bucket [{$public['bucket']}]. "
                .'The public one gets a world-readable custom domain, which would expose '
                .'student submissions. Use two separate buckets.';
        }

        if ($publicDisk === $privateDisk && ($public['driver'] ?? null) === 's3') {
            $problems[] = "UPLOADS_DISK and FILESYSTEM_DISK are both [{$publicDisk}]. Public "
                .'assets and student submissions would share one bucket. Set '
                .'UPLOADS_DISK=r2_public.';
        }

        // A private disk with a public URL is a contradiction worth catching.
        if (! empty($private['url']) && $privateDisk !== 'public') {
            $problems[] = "Private disk [{$privateDisk}] has a 'url' configured. Nothing there "
                .'should be reachable without authorisation.';
        }

        if ($problems === []) {
            $this->info('Storage configuration looks correct.');

            return self::SUCCESS;
        }

        foreach ($problems as $p) {
            $this->error('✗ '.$p);
            $this->newLine();
        }

        return self::FAILURE;
    }
}
