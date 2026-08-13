<?php

namespace App\Services;

use App\Models\Material;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class SignedUrlService
{
    public function __construct(private readonly int $ttlMinutes = 15) {}

    /**
     * Resolve a viewable/downloadable URL for the material. Returns a URL the
     * caller should 302-redirect to.
     */
    public function forMaterial(Material $material, string $disposition = 'inline'): string
    {
        return match ($material->type) {
            Material::TYPE_PDF => $this->urlForPdf($material, $disposition),
            default => $material->external_url ?? '',
        };
    }

    private function urlForPdf(Material $material, string $disposition): string
    {
        $disk = config('filesystems.default');
        $storage = Storage::disk($disk);

        if (in_array($disk, ['r2', 's3'], true)) {
            // R2/S3 lets the caller override Content-Disposition on a signed URL.
            $headers = ['ResponseContentDisposition' => $disposition === 'attachment'
                ? 'attachment; filename="'.$material->title.'.pdf"'
                : 'inline; filename="'.$material->title.'.pdf"'];

            return $storage->temporaryUrl(
                $material->file_path,
                now()->addMinutes($this->ttlMinutes),
                $headers
            );
        }

        if ($material->file_path && $storage->exists($material->file_path)) {
            return URL::temporarySignedRoute(
                'materials.demo-stream',
                now()->addMinutes($this->ttlMinutes),
                ['material' => $material->id, 'disposition' => $disposition]
            );
        }

        // Local dev fallback when seeded file paths don't exist.
        return URL::temporarySignedRoute(
            'materials.demo-placeholder',
            now()->addMinutes($this->ttlMinutes),
            ['material' => $material->id]
        );
    }
}
