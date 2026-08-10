<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

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
    /** Max width — anything wider is scaled down (aspect preserved). */
    private const IMAGE_MAX_WIDTH = 1600;

    /** WebP quality. 80-85 is imperceptible loss with a big size reduction. */
    private const WEBP_QUALITY = 82;

    /**
     * The disk name we're currently pointed at.
     */
    public static function disk(): string
    {
        return config('filesystems.uploads_disk', 'public');
    }

    /**
     * Store an uploaded file under $folder on the configured disk.
     * Returns the stored path (e.g. 'banner-slides/abc123.webp').
     *
     * Raster images (jpeg/png/webp) are transparently compressed on the way
     * in: scaled down to IMAGE_MAX_WIDTH and re-encoded as WebP at
     * WEBP_QUALITY. This typically shrinks phone-camera uploads by 90%+
     * with no perceptible quality loss. Other file types (svg, gif, pdf,
     * video, etc.) pass through unchanged.
     */
    public static function store(UploadedFile $file, string $folder): string
    {
        if (self::shouldCompress($file)) {
            return self::storeCompressedImage($file, $folder);
        }

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

    /**
     * Only compress raster image formats we can safely re-encode.
     *   - JPEG / PNG / WebP → yes
     *   - GIF → skip (would lose animation)
     *   - SVG → skip (vector, already tiny)
     *   - anything else (pdf, mp4, etc.) → skip
     */
    private static function shouldCompress(UploadedFile $file): bool
    {
        return in_array(strtolower($file->getMimeType() ?? ''), [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/webp',
        ], true);
    }

    /**
     * Read → downscale if oversized → encode as WebP → write to disk.
     * Returns the stored path (folder/uuid.webp).
     *
     * Falls back to storing the raw upload untouched if Intervention throws
     * — better a large file than a broken upload.
     */
    private static function storeCompressedImage(UploadedFile $file, string $folder): string
    {
        try {
            $manager = ImageManager::gd();
            $image = $manager->read($file->getRealPath());

            // scaleDown only shrinks, never upscales — safe for small images.
            if ($image->width() > self::IMAGE_MAX_WIDTH) {
                $image->scaleDown(width: self::IMAGE_MAX_WIDTH);
            }

            $encoded = $image->encode(new WebpEncoder(quality: self::WEBP_QUALITY));

            $path = trim($folder, '/').'/'.Str::uuid().'.webp';
            Storage::disk(self::disk())->put($path, (string) $encoded);
            return $path;
        } catch (\Throwable $e) {
            Log::warning('StoredFile: image compression failed, storing original — '.$e->getMessage());
            return $file->store($folder, self::disk());
        }
    }
}
