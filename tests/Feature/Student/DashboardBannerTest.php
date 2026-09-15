<?php

namespace Tests\Feature\Student;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The image announcements a logged-in user sees at the top of the
 * dashboard. They fill the width of the page, like the public homepage's
 * posters: no centred column and no width cap.
 */
class DashboardBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_banner_has_no_width_cap(): void
    {
        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $student = User::factory()->create(['is_active' => true]);
        $student->assignRole('student');
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        Announcement::create([
            'title' => 'BANNER_ONE',
            'body' => '',
            'type' => Announcement::TYPE_IMAGE,
            'image_path' => 'announcement-images/a.webp',
            'audience' => 'all',
            'course_id' => null,
            'starts_at' => now()->subHour(),
            'is_active' => true,
            'created_by_user_id' => $admin->id,
        ]);

        $html = $this->actingAs($student)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('alt="BANNER_ONE"', $html);

        $banner = substr($html, strpos($html, 'alt="BANNER_ONE"') - 1500, 1500);
        $this->assertStringNotContainsString('max-w-', $banner, 'The banner frame has no width cap.');
        $this->assertStringNotContainsString('mx-auto', $banner, 'Nor is it a centred column.');
        $this->assertStringContainsString('class="relative w-full overflow-hidden"', $banner);
    }
}
