<?php

namespace Tests\Feature\Admin;

use App\Models\BannerSlide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BannerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_banner_index_loads_for_admin(): void
    {
        $this->actingAs($this->admin)->get('/banner')->assertOk();
    }

    public function test_non_admin_cannot_access_banner_admin(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($student)->get('/banner')->assertForbidden();
    }

    public function test_admin_can_upload_a_slide(): void
    {
        Storage::fake('public');

        $image = UploadedFile::fake()->image('hero.jpg', 1600, 900);

        $this->actingAs($this->admin)
            ->post('/banner', [
                'image' => $image,
                'title' => 'Welcome',
                'subtitle' => 'Belajar dengan teratur',
                'sort_order' => 1,
                'is_active' => '1',
            ])
            ->assertRedirect('/banner');

        $slide = BannerSlide::where('title', 'Welcome')->first();
        $this->assertNotNull($slide);
        $this->assertSame('Belajar dengan teratur', $slide->subtitle);
        Storage::disk('public')->assertExists($slide->image_path);
    }

    public function test_public_landing_shows_active_slides_in_order(): void
    {
        BannerSlide::create(['image_path' => 'banner-slides/a.jpg', 'title' => 'SLIDE_FIRST',  'sort_order' => 1, 'is_active' => true]);
        BannerSlide::create(['image_path' => 'banner-slides/b.jpg', 'title' => 'SLIDE_SECOND', 'sort_order' => 2, 'is_active' => true]);
        BannerSlide::create(['image_path' => 'banner-slides/c.jpg', 'title' => 'SLIDE_HIDDEN', 'sort_order' => 3, 'is_active' => false]);

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('SLIDE_FIRST')
            ->assertSee('SLIDE_SECOND')
            ->assertDontSee('SLIDE_HIDDEN');

        // Order: first slide should appear before second in HTML.
        $body = $response->getContent();
        $this->assertLessThan(strpos($body, 'SLIDE_SECOND'), strpos($body, 'SLIDE_FIRST'));
    }

    public function test_public_landing_falls_back_to_default_hero_when_no_slides(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Selamat datang');
    }

    public function test_admin_can_delete_a_slide(): void
    {
        Storage::fake('public');

        $slide = BannerSlide::create([
            'image_path' => 'banner-slides/test.jpg',
            'title' => 'gone',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        Storage::disk('public')->put($slide->image_path, 'fake content');

        $this->actingAs($this->admin)
            ->delete('/banner/'.$slide->id)
            ->assertRedirect('/banner');

        $this->assertDatabaseMissing('banner_slides', ['id' => $slide->id]);
        Storage::disk('public')->assertMissing('banner-slides/test.jpg');
    }
}
