<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files that are NOT reachable by URL.
 *
 * This disk sits outside the web root, so there is no address a browser can
 * hit. The only way a byte leaves here is through a controller that has
 * already authorised the caller — which is exactly what you want for
 * material PDFs, student submissions, exports and imports.
 *
 * Deliberately has no url() method. If you find yourself wanting one, the
 * answer is a controller route that checks permissions and then calls
 * PrivateFile::response(), not a public URL.
 *
 * Images are NOT re-encoded here (see StoredFile::$compressImages): a
 * student's submitted work has to come back byte-for-byte as they sent it.
 */
class PrivateFile extends StoredFile
{
    public static function disk(): string
    {
        return config('filesystems.default', 'local');
    }

    /**
     * Stream the file to the browser. Call this only after authorising the
     * request — this method intentionally performs no checks of its own.
     */
    public static function response(
        string $path,
        string $downloadName,
        string $mimeType = 'application/octet-stream',
        string $disposition = 'attachment',
    ): StreamedResponse {
        return Storage::disk(static::disk())->response($path, $downloadName, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition.'; filename="'.addslashes($downloadName).'"',
        ]);
    }

    /**
     * Absolute filesystem path, when a local path is genuinely needed (e.g.
     * streaming into a ZipArchive without buffering the file in memory).
     * Returns null on disks that have no local path, such as R2/S3.
     */
    public static function path(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        try {
            $local = Storage::disk(static::disk())->path($path);

            return is_file($local) ? $local : null;
        } catch (\Throwable) {
            return null; // cloud disk — caller should fall back to get()
        }
    }

    /**
     * Raw file contents. Use for small files, or as the fallback when
     * path() returns null on a cloud disk.
     */
    public static function get(string $path): string
    {
        return Storage::disk(static::disk())->get($path);
    }
}
