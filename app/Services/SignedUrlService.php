<?php

namespace App\Services;

use App\Jobs\LogMaterialAccessJob;
use App\Models\AccessLog;
use App\Models\Material;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class SignedUrlService
{
    public function __construct(private readonly int $ttlMinutes = 15) {}

    /**
     * Resolve a viewable/downloadable URL for the material AND log the access.
     * Returns a URL the caller should 302-redirect to.
     */
    public function forMaterial(Material $material, User $user, Request $request): string
    {
        $this->logAccess($material, $user, $request);

        return match ($material->type) {
            Material::TYPE_PDF => $this->urlForPdf($material),
            default => $material->external_url ?? '',
        };
    }

    private function urlForPdf(Material $material): string
    {
        $disk = config('filesystems.default');
        $storage = Storage::disk($disk);

        if (in_array($disk, ['r2', 's3'], true)) {
            return $storage->temporaryUrl($material->file_path, now()->addMinutes($this->ttlMinutes));
        }

        if ($material->file_path && $storage->exists($material->file_path)) {
            return URL::temporarySignedRoute(
                'materials.demo-stream',
                now()->addMinutes($this->ttlMinutes),
                ['material' => $material->id]
            );
        }

        // Local dev fallback when seeded file paths don't exist.
        return URL::temporarySignedRoute(
            'materials.demo-placeholder',
            now()->addMinutes($this->ttlMinutes),
            ['material' => $material->id]
        );
    }

    private function logAccess(Material $material, User $user, Request $request): void
    {
        LogMaterialAccessJob::dispatch(
            userId: $user->id,
            materialId: $material->id,
            action: $material->type === Material::TYPE_PDF
                ? AccessLog::ACTION_DOWNLOAD
                : AccessLog::ACTION_VIEW,
            ipAddress: $request->ip() ?? '0.0.0.0',
            userAgent: $request->userAgent(),
            accessedAt: now(),
        );
    }
}
