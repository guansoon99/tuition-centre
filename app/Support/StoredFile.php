<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Single abstraction for user-uploaded public files — banners, announcement
 * images, course banners, site logo, Quill inline images, etc.
 *
 * All storage / cleanup / URL generation for those files goes through here
 * so switching the underlying disk (local → Cloudflare R2, say) is a one-line
 * env change (UPLOADS_DISK=r2) rather than a codebase-wide refactor.
 *
 * Reads the disk from config('filesystems.uploads_disk') which defaults to
 * 'public' (the local disk served via the /storage symlink).
 */
class StoredFile
{
    /**
     * The disk name we're currently pointed at.
     */
    public static function disk(): string
    {
        return config('filesystems.uploads_disk', 'public');
    }

    /**
     * Store an uploaded file under $folder on the configured disk.
     * Returns the stored path (e.g. 'banner-slides/abc123.png').
     */
    public static function store(UploadedFile $file, string $folder): string
    {
        return $file->store($folder, self::disk());
    }

    /**
     * Delete the file at $path if it exists. Null-safe — pass a nullable
     * column value directly. Never throws when the file's already gone.
     */
    public static function forget(?string $path): void
    {
        if (! $path) {
            return;
        }

        $disk = Storage::disk(self::disk());
        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }

    /**
     * Public URL for a stored file. Works transparently for local (returns
     * /storage/<path> via the symlink) and for cloud disks (returns the
     * signed / public HTTPS URL configured on the disk).
     */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Storage::disk(self::disk())->url($path);
    }
}
