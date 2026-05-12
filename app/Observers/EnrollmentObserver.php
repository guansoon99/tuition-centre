<?php

namespace App\Observers;

use App\Models\Enrollment;
use App\Support\Cache\CacheKeys;
use Illuminate\Support\Facades\Cache;

class EnrollmentObserver
{
    public function saved(Enrollment $enrollment): void
    {
        Cache::forget(CacheKeys::userEnrolled($enrollment->user_id));
        Cache::forget(CacheKeys::userRecent($enrollment->user_id));
    }

    public function deleted(Enrollment $enrollment): void
    {
        $this->saved($enrollment);
    }
}
