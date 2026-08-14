<?php

namespace Tests\Feature;

use App\Models\BannerSlide;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\SiteSettings;
use App\Models\User;
use App\Support\PrivateFile;
use App\Support\PublicFile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Replacing a file must remove the one it replaced.
 *
 * A missed delete here is invisible: the page shows the new image and the old
 * object just sits in the bucket forever. An admin swapping a logo a few times
 * a year is trivial; a teacher re-uploading course PDFs is not.
 *
 * These assert on the ORDER as much as the behaviour — the old path has to be
 * read before the row is updated, or there is nothing left to delete.
 */
class ReplaceCleansUpOldFileTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake(PrivateFile::disk());
        Storage::fake(PublicFile::disk());

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function seedFile(string $disk, string $path): string
    {
        Storage::disk($disk)->put($path, 'old bytes');

        return $path;
    }

    public function test_replacing_the_site_logo_removes_the_old_one(): void
    {
        $old = $this->seedFile(PublicFile::disk(), 'site/old-logo.webp');
        // firstOrCreate, not query()->update(): the table is empty under
        // RefreshDatabase, so an update would match nothing and the controller
        // would then create a fresh row with no logo to replace.
        SiteSettings::firstOrCreate(['id' => 1])->update(['logo_path' => $old]);
        SiteSettings::forgetCache();

        $this->actingAs($this->admin)
            ->patch(route('settings.update'), [
                'name' => 'LMS Site',
                'logo' => UploadedFile::fake()->image('new-logo.png', 400, 400),
            ])->assertSessionHasNoErrors()->assertRedirect();

        Storage::disk(PublicFile::disk())->assertMissing($old);
        $this->assertNotSame($old, SiteSettings::current()->logo_path);
    }

    /**
     * "Required" is a rule about the result, not the field. Removing is still
     * allowed — you just cannot save a removal on its own, because the logo
     * renders on the login page where there is no session to authorise
     * anything.
     */
    public function test_settings_cannot_be_saved_without_a_logo_when_none_is_set(): void
    {
        SiteSettings::firstOrCreate(['id' => 1])->update(['logo_path' => null]);
        SiteSettings::forgetCache();

        $this->actingAs($this->admin)
            ->patch(route('settings.update'), ['name' => 'LMS Site'])
            ->assertSessionHasErrors('logo');
    }

    public function test_removing_the_logo_without_a_replacement_is_refused(): void
    {
        $existing = $this->seedFile(PublicFile::disk(), 'site/current.webp');
        SiteSettings::firstOrCreate(['id' => 1])->update(['logo_path' => $existing]);
        SiteSettings::forgetCache();

        $this->actingAs($this->admin)
            ->patch(route('settings.update'), [
                'name' => 'LMS Site',
                'remove_logo' => '1',
            ])
            ->assertSessionHasErrors('logo');

        // Refused, so the existing logo must be untouched.
        Storage::disk(PublicFile::disk())->assertExists($existing);
        $this->assertSame($existing, SiteSettings::current()->logo_path);
    }

    public function test_removing_and_replacing_in_one_save_is_allowed(): void
    {
        $old = $this->seedFile(PublicFile::disk(), 'site/outgoing.webp');
        SiteSettings::firstOrCreate(['id' => 1])->update(['logo_path' => $old]);
        SiteSettings::forgetCache();

        $this->actingAs($this->admin)
            ->patch(route('settings.update'), [
                'name' => 'LMS Site',
                'remove_logo' => '1',
                'logo' => UploadedFile::fake()->image('incoming.png', 400, 400),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Storage::disk(PublicFile::disk())->assertMissing($old);
        $this->assertNotNull(SiteSettings::current()->logo_path);
    }

    public function test_saving_without_a_file_keeps_the_existing_logo(): void
    {
        $existing = $this->seedFile(PublicFile::disk(), 'site/keep-me.webp');
        SiteSettings::firstOrCreate(['id' => 1])->update(['logo_path' => $existing]);
        SiteSettings::forgetCache();

        $this->actingAs($this->admin)
            ->patch(route('settings.update'), ['name' => 'Renamed'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Storage::disk(PublicFile::disk())->assertExists($existing);
        $this->assertSame($existing, SiteSettings::current()->logo_path);
    }

    public function test_replacing_a_banner_image_removes_the_old_one(): void
    {
        $old = $this->seedFile(PublicFile::disk(), 'banner-slides/old.webp');
        $slide = BannerSlide::create([
            'image_path' => $old, 'sort_order' => 1, 'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('banner.update', $slide), [
                'title' => 'Welcome',
                'image' => UploadedFile::fake()->image('new.png', 800, 400),
            ])->assertSessionHasNoErrors()->assertRedirect();

        Storage::disk(PublicFile::disk())->assertMissing($old);
    }

    public function test_replacing_a_course_banner_removes_the_old_one(): void
    {
        $old = $this->seedFile(PublicFile::disk(), 'course-banners/old.webp');
        $course = Course::factory()->create(['is_active' => true, 'banner_image' => $old]);

        $this->actingAs($this->admin)
            ->patch(route('courses.update', $course), [
                'code' => $course->code,
                'name' => $course->name,
                'banner_image' => UploadedFile::fake()->image('new.png', 800, 400),
                'is_active' => '1',
            ])->assertSessionHasNoErrors()->assertRedirect();

        Storage::disk(PublicFile::disk())->assertMissing($old);
    }

    public function test_replacing_a_material_pdf_removes_the_old_one(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);

        $staff = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $staff->givePermissionTo('sections.manage');
        $teacher = User::factory()->create(['is_active' => true]);
        $teacher->assignRole('teacher');
        Enrollment::create([
            'course_id' => $course->id, 'user_id' => $teacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $old = $this->seedFile(PrivateFile::disk(), 'materials/1/1/old.pdf');
        $material = Material::factory()->create([
            'section_id' => $section->id, 'type' => Material::TYPE_PDF, 'file_path' => $old,
        ]);

        $this->actingAs($teacher)
            ->patch(route('materials.update', $material), [
                'title' => 'Updated',
                'type' => Material::TYPE_PDF,
                'file' => UploadedFile::fake()->create('new.pdf', 20, 'application/pdf'),
            ])->assertSessionHasNoErrors()->assertRedirect();

        Storage::disk(PrivateFile::disk())->assertMissing($old);
    }
}
