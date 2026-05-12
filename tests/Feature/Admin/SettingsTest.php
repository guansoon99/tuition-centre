<?php

namespace Tests\Feature\Admin;

use App\Models\SiteSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingsTest extends TestCase
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

    public function test_settings_page_loads_for_admin(): void
    {
        $this->actingAs($this->admin)->get('/settings')->assertOk();
    }

    public function test_non_admin_cannot_access_settings(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($student)->get('/settings')->assertForbidden();
    }

    public function test_admin_can_save_centre_name_and_contact(): void
    {
        $this->actingAs($this->admin)
            ->patch('/settings', [
                'name' => 'Qin Education',
                'contact_phone' => '+60 12-345 6789',
                'contact_address' => 'Jalan Tun Razak',
                'contact_hours' => 'Mon-Sat 9am-9pm',
            ])
            ->assertRedirect('/settings');

        $settings = SiteSettings::current();
        $this->assertSame('Qin Education', $settings->name);
        $this->assertSame('+60 12-345 6789', $settings->contact_phone);
    }

    public function test_admin_can_upload_logo(): void
    {
        Storage::fake('public');
        $logo = UploadedFile::fake()->image('logo.png', 200, 60);

        $this->actingAs($this->admin)
            ->patch('/settings', [
                'name' => 'Centre',
                'logo' => $logo,
            ])
            ->assertRedirect();

        $settings = SiteSettings::current();
        $this->assertNotNull($settings->logo_path);
        Storage::disk('public')->assertExists($settings->logo_path);
    }

    public function test_admin_can_remove_logo_via_main_form(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('site/old-logo.png', 'fake');

        $settings = SiteSettings::current();
        $settings->update(['logo_path' => 'site/old-logo.png']);
        SiteSettings::forgetCache();

        $this->actingAs($this->admin)
            ->patch('/settings', [
                'name' => 'Centre',
                'remove_logo' => '1',
            ])
            ->assertRedirect('/settings');

        $this->assertNull(SiteSettings::current()->logo_path);
        Storage::disk('public')->assertMissing('site/old-logo.png');
    }

    public function test_blank_centre_name_falls_back_to_app_name(): void
    {
        SiteSettings::current()->update(['name' => null]);
        SiteSettings::forgetCache();

        $this->assertSame(config('app.name'), SiteSettings::current()->displayName());
    }

}
