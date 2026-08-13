<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Files served straight off the web root, with NO authentication.
 *
 * ⚠ Anything stored here is fetchable by anyone on the internet who has the
 * URL — no login, no permission check, no PHP involved. `public/storage` is
 * a symlink to this disk, so the web server hands the file over directly.
 *
 * Only use this for assets that are genuinely meant to be public:
 *   - banner slides   (shown on the landing page, to guests)
 *   - the site logo   (favicon + login page, needed before auth)
 *
 * Anything tied to a student, a course, or a submission belongs in
 * PrivateFile instead. If you are unsure, it is PrivateFile.
 *
 * Audit what is world-readable at any time with:  grep -rn "PublicFile::store"
 */
class PublicFile extends StoredFile
{
    /**
     * Public assets are ours and often phone photos, so re-encoding to WebP
     * is a straight win. See StoredFile::$compressImages.
     */
    protected static bool $compressImages = true;

    public static function disk(): string
    {
        return config('filesystems.uploads_disk', 'public');
    }

    /**
     * Publicly reachable URL for a stored file. Works for local (returns
     * /storage/<path> via the symlink) and for cloud disks (returns the
     * configured HTTPS URL).
     */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        static::warnIfNotPubliclyReachable();

        return Storage::disk(static::disk())->url($path);
    }

    /**
     * An s3 disk with no 'url' configured is the worst kind of broken: the
     * driver happily builds one from the API endpoint, which needs SigV4 auth,
     * so every image 403s with no exception and nothing in the log. The page
     * renders, the pictures just aren't there.
     *
     * Logging does not fix it, but it turns an invisible failure into a
     * searchable one. `php artisan storage:check` catches it before deploy,
     * which is where you actually want to find out.
     */
    protected static function warnIfNotPubliclyReachable(): void
    {
        static $warned = false;

        if ($warned) {
            return;   // once per request, not once per image
        }

        $disk = static::disk();
        $config = config('filesystems.disks.'.$disk, []);

        if (($config['driver'] ?? null) === 's3' && empty($config['url'])) {
            $warned = true;

            Log::error(
                "Public uploads disk [{$disk}] has no 'url' configured, so its URLs point at "
                ."the private S3 API endpoint and will 403. Set R2_PUBLIC_URL to the bucket's "
                .'custom domain. Run `php artisan storage:check`.'
            );
        }
    }
}
