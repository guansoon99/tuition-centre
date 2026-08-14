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

    /**
     * Whether this disk can hand the browser a URL to upload straight to.
     * False on the local disk, which is what dev runs on — callers must keep
     * a path that proxies the upload through PHP for that case.
     */
    public static function canPresign(): bool
    {
        // Not method_exists(): FilesystemAdapter declares temporaryUploadUrl()
        // for every driver and throws "does not support" at call time, so the
        // method being present says nothing. The driver is what decides.
        return config('filesystems.disks.'.static::disk().'.driver') === 's3';
    }

    /**
     * A short-lived URL the browser can PUT one file to, bypassing this
     * server entirely.
     *
     * ⚠ ContentLength and ContentType below do NOT constrain the upload.
     *
     * Tested against a real R2 bucket: the presigned URL comes back with
     * `X-Amz-SignedHeaders=host` — only the host is covered. A URL signed for
     * 100 bytes accepted a 5,009-byte body with a 200. So the declared size
     * and type are advisory; a client is free to ignore both.
     *
     * They are still passed because they cost nothing and a future R2 change
     * (or a move to S3, which does support this) would start honouring them.
     * But nothing may depend on it. The size and type guarantees live entirely
     * in SubmissionController::register(), which HEADs the stored object and
     * sniffs its leading bytes after the fact.
     */
    public static function presignPut(
        string $path,
        int $bytes,
        string $contentType,
        int $ttlMinutes = 5,
    ): array {
        return Storage::disk(static::disk())->temporaryUploadUrl(
            $path,
            now()->addMinutes($ttlMinutes),
            [
                'ContentLength' => $bytes,
                'ContentType' => $contentType,
            ],
        );
    }

    /** Size of the stored object in bytes. */
    public static function sizeOf(string $path): int
    {
        return (int) Storage::disk(static::disk())->size($path);
    }

    /**
     * The first $n bytes of a stored file, for magic-byte type sniffing.
     *
     * Fetched with an HTTP Range request on S3/R2 so a 200 MB submission
     * costs one small read rather than a full download. finfo only ever looks
     * at the leading bytes anyway — which is all the `mimetypes:` validation
     * rule was doing back when uploads passed through PHP.
     */
    public static function leadingBytes(string $path, int $n = 4096): string
    {
        $disk = Storage::disk(static::disk());

        if (method_exists($disk, 'getClient')) {
            try {
                $result = $disk->getClient()->getObject([
                    'Bucket' => config('filesystems.disks.'.static::disk().'.bucket'),
                    'Key' => $path,
                    'Range' => 'bytes=0-'.($n - 1),
                ]);

                return (string) $result['Body'];
            } catch (\Throwable) {
                // Fall through to the stream read below.
            }
        }

        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            return '';
        }

        try {
            return (string) fread($stream, $n);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Sniff the real MIME type from the stored bytes, ignoring whatever the
     * client claimed. Returns null if it cannot be determined.
     */
    public static function sniffMimeType(string $path): ?string
    {
        $head = static::leadingBytes($path);

        if ($head === '') {
            return null;
        }

        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($head) ?: null;
    }
}
