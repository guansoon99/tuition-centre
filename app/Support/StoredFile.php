<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Shared behaviour for the two file stores. Do not use directly — pick the
 * one that names the visibility you want:
 *
 *   PublicFile   anything served straight off the web root with NO auth
 *                check: banner slides, the site logo. Once stored, the file
 *                is fetchable by anyone who has (or guesses) the URL.
 *
 *   PrivateFile  everything else: material PDFs, student submissions,
 *                exports, imports. Unreachable by URL; the only way out is
 *                through a controller that authorises the caller first.
 *
 * The split is deliberate and load-bearing. Reach for the wrong one and a
 * file that should be gated becomes world-readable, with nothing failing to
 * tell you. When adding an upload, ask "should a stranger be able to open
 * this?" — if the answer is anything other than a confident yes, it is
 * PrivateFile.
 */
abstract class StoredFile
{
    /** Max width — anything wider is scaled down (aspect preserved). */
    protected const IMAGE_MAX_WIDTH = 1600;

    /** WebP quality. 80-85 is imperceptible loss with a big size reduction. */
    protected const WEBP_QUALITY = 82;

    /**
     * Whether raster images are re-encoded on the way in. On for public
     * assets (they're ours, and shrinking phone photos matters). Off for
     * private files — a student's submitted work must land byte-for-byte as
     * they uploaded it.
     */
    protected static bool $compressImages = false;

    /** The disk this store writes to. */
    abstract public static function disk(): string;

    /**
     * Store an uploaded file under $folder. Returns the stored path.
     */
    public static function store(UploadedFile $file, string $folder): string
    {
        if (static::$compressImages && static::shouldCompress($file)) {
            return static::storeCompressedImage($file, $folder);
        }

        return $file->store($folder, static::disk());
    }

    /**
     * Store under an explicit filename (no compression, no renaming).
     * Used where the caller needs a deterministic name.
     */
    public static function storeAs(UploadedFile $file, string $folder, string $name): string
    {
        return $file->storeAs($folder, $name, static::disk());
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

        $disk = Storage::disk(static::disk());
        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }

    public static function exists(?string $path): bool
    {
        return $path ? Storage::disk(static::disk())->exists($path) : false;
    }

    /**
     * Only compress raster image formats we can safely re-encode.
     *   - JPEG / PNG / WebP → yes
     *   - GIF → skip (would lose animation)
     *   - SVG → skip (vector, already tiny)
     *   - anything else (pdf, mp4, etc.) → skip
     */
    protected static function shouldCompress(UploadedFile $file): bool
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
    protected static function storeCompressedImage(UploadedFile $file, string $folder): string
    {
        try {
            $manager = ImageManager::gd();
            $image = $manager->read($file->getRealPath());

            // scaleDown only shrinks, never upscales — safe for small images.
            if ($image->width() > static::IMAGE_MAX_WIDTH) {
                $image->scaleDown(width: static::IMAGE_MAX_WIDTH);
            }

            $encoded = $image->encode(new WebpEncoder(quality: static::WEBP_QUALITY));

            $path = trim($folder, '/').'/'.Str::uuid().'.webp';
            Storage::disk(static::disk())->put($path, (string) $encoded);

            return $path;
        } catch (\Throwable $e) {
            Log::warning('StoredFile: image compression failed, storing original — '.$e->getMessage());

            return $file->store($folder, static::disk());
        }
    }
}
