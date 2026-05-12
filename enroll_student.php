<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Course;
use App\Models\User;

$student = User::where('username', 'student')->first();
if (! $student) {
    fwrite(STDERR, "User 'student' not found.\n");
    exit(1);
}

// Enrol in the first 2 active courses (mirrors what the admin would do via /manage/courses).
$courses = Course::where('is_active', true)->orderBy('id')->limit(2)->get();

if ($courses->isEmpty()) {
    fwrite(STDERR, "No active courses to enrol in.\n");
    exit(1);
}

foreach ($courses as $course) {
    $student->enrolledCourses()->syncWithoutDetaching([
        $course->id => [
            'enrolled_at' => now(),
            'is_active' => true,
        ],
    ]);
    echo "  Enrolled in {$course->code} — {$course->name}".PHP_EOL;
}

echo PHP_EOL;
echo "Now visible to {$student->username}: ".Course::visibleTo($student)->count()." courses".PHP_EOL;
