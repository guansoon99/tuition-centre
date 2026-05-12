<?php

namespace App\Observers;

use App\Models\Material;
use App\Support\Cache\CacheKeys;
use Illuminate\Support\Facades\Cache;

class MaterialObserver
{
    public function saved(Material $material): void
    {
        $courseId = $material->section?->course_id ?? $material->section()->value('course_id');
        if ($courseId) {
            Cache::forget(CacheKeys::courseDetail($courseId));
        }
    }

    public function deleted(Material $material): void
    {
        $this->saved($material);
    }
}
