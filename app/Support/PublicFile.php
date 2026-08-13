<?php

namespace App\Support;

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

        return Storage::disk(static::disk())->url($path);
    }
}
