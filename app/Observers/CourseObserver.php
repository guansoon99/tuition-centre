<?php

namespace App\Observers;

use App\Models\Course;
use App\Support\Cache\CacheKeys;
use Illuminate\Support\Facades\Cache;

class CourseObserver
{
    public function saved(Course $course): void
    {
        Cache::forget(CacheKeys::courseDetail($course->id));
    }

    public function deleted(Course $course): void
    {
        Cache::forget(CacheKeys::courseDetail($course->id));
    }
}
