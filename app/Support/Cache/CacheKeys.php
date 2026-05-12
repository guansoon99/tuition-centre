<?php

namespace App\Support\Cache;

class CacheKeys
{
    public const TTL_COURSE_DETAIL = 600;
    public const TTL_ENROLLED = 300;
    public const TTL_ASSIGNED = 300;
    public const TTL_RECENT = 60;

    public static function courseDetail(int $courseId): string
    {
        return "course:{$courseId}:detail";
    }

    public static function userEnrolled(int $userId): string
    {
        return "user:{$userId}:enrolled_courses";
    }

    public static function userAssigned(int $userId): string
    {
        return "user:{$userId}:assigned_courses";
    }

    public static function userRecent(int $userId): string
    {
        return "user:{$userId}:recently_accessed";
    }
}
