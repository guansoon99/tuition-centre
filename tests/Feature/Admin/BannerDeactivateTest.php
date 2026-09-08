<?php

namespace Tests\Feature\Admin;

use App\Models\BannerSlide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Deactivating a slide hides it from the homepage without deleting it.
 *
 * is_active existed before this, but every save forced it to true and the
 * only way to take a slide down was Delete — image and all.
 */
class BannerDeactivateTest extends TestCase
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

    /**
     * Back to an anonymous request. actingAs() sticks for the rest of the
     * test, and slides only render on the public landing page: the admin's
     * own homepage is the dashboard, where "slide not seen" proves nothing.
     */
    private function asGuest(): static
    {
        auth()->logout();

        return $this;
    }

    private function slide(bool $active = true): BannerSlide
    {
        return BannerSlide::create([
            'image_path' => 'banner-slides/a.jpg',
            'title' => 'SLIDE_TOGGLED',
            'sort_order' => 1,
            'is_active' => $active,
        ]);
    }

    public function test_deactivating_hides_the_slide_from_the_homepage(): void
    {
        $slide = $this->slide();

        // Warm the homepage cache first: the flip has to clear it, or the
        // slide lingers for up to five minutes after being hidden.
        $this->get('/')->assertSee('SLIDE_TOGGLED');

        $this->actingAs($this->admin)
            ->post(route('banner.deactivate', $slide))
            ->assertRedirect(route('banner.index'));

        $this->assertFalse($slide->fresh()->is_active);
        $this->asGuest()->get('/')->assertOk()->assertDontSee('SLIDE_TOGGLED');
    }

    public function test_activating_shows_it_again(): void
    {
        $slide = $this->slide(false);
        $this->get('/')->assertDontSee('SLIDE_TOGGLED');

        $this->actingAs($this->admin)
            ->post(route('banner.activate', $slide))
            ->assertRedirect(route('banner.index'));

        $this->assertTrue($slide->fresh()->is_active);
        $this->asGuest()->get('/')->assertSee('SLIDE_TOGGLED');
    }

    public function test_the_list_shows_the_status_and_the_matching_button(): void
    {
        $this->slide(false);

        $this->actingAs($this->admin)
            ->get(route('banner.index'))
            ->assertOk()
            ->assertSee('Inactive')
            ->assertSee('Activate')
            ->assertDontSee('Deactivate');
    }

    public function test_editing_a_hidden_slide_does_not_reshow_it(): void
    {
        $slide = $this->slide(false);

        $this->actingAs($this->admin)
            ->patch(route('banner.update', $slide), ['title' => 'Renamed'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('banner.index'));

        $this->assertSame('Renamed', $slide->fresh()->title);
        $this->assertFalse($slide->fresh()->is_active, 'Saving the edit form must not re-activate a hidden slide.');
    }

    public function test_hiding_a_slide_needs_the_edit_permission(): void
    {
        $slide = $this->slide();
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($student)
            ->post(route('banner.deactivate', $slide))
            ->assertForbidden();

        $this->assertTrue($slide->fresh()->is_active);
    }
}
