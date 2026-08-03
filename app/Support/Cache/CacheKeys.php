<?php

namespace App\Support\Cache;

class CacheKeys
{
    public const TTL_COURSE_DETAIL = 600;
    public const TTL_ENROLLED = 300;
    public const TTL_RECENT = 60;

    public static function courseDetail(int $courseId): string
    {
        return "course:{$courseId}:detail";
    }

    /**
     * Course list shown on the Home page. Populated by Student\HomeController
     * from Course::visibleTo($user) — includes courses the user is enrolled
     * in as student AND assigned to as teacher (the merged enrollments table
     * makes both come through the same relationship).
     */
    public static function userEnrolled(int $userId): string
    {
        return "user:{$userId}:enrolled_courses";
    }

    public static function userRecent(int $userId): string
    {
        return "user:{$userId}:recently_accessed";
    }
}
