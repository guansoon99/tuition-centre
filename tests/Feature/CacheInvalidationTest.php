<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\User;
use App\Support\Cache\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    public function test_course_detail_cache_invalidated_when_course_changes(): void
    {
        $course = Course::factory()->create();
        Cache::put(CacheKeys::courseDetail($course->id), 'cached');

        $course->update(['name' => 'New name']);

        $this->assertNull(Cache::get(CacheKeys::courseDetail($course->id)));
    }

    public function test_course_detail_cache_invalidated_when_section_added(): void
    {
        $course = Course::factory()->create();
        Cache::put(CacheKeys::courseDetail($course->id), 'cached');

        Section::factory()->create(['course_id' => $course->id]);

        $this->assertNull(Cache::get(CacheKeys::courseDetail($course->id)));
    }

    public function test_course_detail_cache_invalidated_when_material_added(): void
    {
        $course = Course::factory()->create();
        $section = Section::factory()->create(['course_id' => $course->id]);

        Cache::put(CacheKeys::courseDetail($course->id), 'cached');

        Material::factory()->create(['section_id' => $section->id]);

        $this->assertNull(Cache::get(CacheKeys::courseDetail($course->id)));
    }

    public function test_user_enrollment_cache_invalidated_when_enrollment_added(): void
    {
        $user = User::factory()->create();
        Cache::put(CacheKeys::userEnrolled($user->id), 'cached');
        Cache::put(CacheKeys::userRecent($user->id), 'recent');

        Enrollment::create([
            'user_id' => $user->id,
            'course_id' => Course::factory()->create()->id,
            'enrolled_at' => now(),
            'is_active' => true,
        ]);

        $this->assertNull(Cache::get(CacheKeys::userEnrolled($user->id)));
        $this->assertNull(Cache::get(CacheKeys::userRecent($user->id)));
    }

    public function test_visiting_course_invalidates_user_recent_cache(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $course = Course::factory()->create();
        $student->enrolledCourses()->attach($course, ['enrolled_at' => now(), 'is_active' => true]);

        Cache::put(CacheKeys::userRecent($student->id), 'cached_recent');

        $this->actingAs($student)->get('/courses/'.$course->slug);

        $this->assertNull(Cache::get(CacheKeys::userRecent($student->id)));
    }
}
