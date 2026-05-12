<?php

namespace App\Observers;

use App\Models\Section;
use App\Support\Cache\CacheKeys;
use Illuminate\Support\Facades\Cache;

class SectionObserver
{
    public function saved(Section $section): void
    {
        Cache::forget(CacheKeys::courseDetail($section->course_id));
    }

    public function deleted(Section $section): void
    {
        Cache::forget(CacheKeys::courseDetail($section->course_id));
    }
}
