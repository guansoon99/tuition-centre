<?php

namespace Tests\Feature\Admin;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A contact can be switched off and on from the list without deleting it.
 * Off, its floating button disappears from the logged-in pages; the list
 * shows the state and the matching button, and editing does not switch it
 * back on.
 */
class ContactDeactivateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->student = User::factory()->create(['is_active' => true]);
        $this->student->assignRole('student');
    }

    /** Log in as $user with a clean session (a second actor in one test). */
    private function as(User $user): static
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->actingAs($user);
    }

    private function contact(bool $active = true): Contact
    {
        return Contact::create([
            'type' => Contact::TYPE_WHATSAPP,
            'value' => '011 7240 3112',
            'label' => 'CONTACT_TOGGLED',
            'sort_order' => 1,
            'is_active' => $active,
        ]);
    }

    public function test_deactivating_hides_the_floating_button(): void
    {
        $contact = $this->contact();

        $this->as($this->student)->get('/')->assertOk()->assertSee('CONTACT_TOGGLED');

        $this->as($this->admin)
            ->post(route('contacts.deactivate', $contact))
            ->assertRedirect(route('contacts.index'));

        $this->assertFalse($contact->fresh()->is_active);
        $this->as($this->student)->get('/')->assertOk()->assertDontSee('CONTACT_TOGGLED');
    }

    public function test_activating_shows_it_again(): void
    {
        $contact = $this->contact(active: false);

        $this->as($this->student)->get('/')->assertOk()->assertDontSee('CONTACT_TOGGLED');

        $this->as($this->admin)
            ->post(route('contacts.activate', $contact))
            ->assertRedirect(route('contacts.index'));

        $this->assertTrue($contact->fresh()->is_active);
        $this->as($this->student)->get('/')->assertOk()->assertSee('CONTACT_TOGGLED');
    }

    public function test_the_list_shows_the_status_the_matching_button_and_a_green_row_when_active(): void
    {
        $on = $this->contact();
        $off = Contact::create(['type' => Contact::TYPE_PHONE, 'value' => '03 1234 5678', 'label' => 'OFF_ONE', 'sort_order' => 2, 'is_active' => false]);

        $html = $this->as($this->admin)->get(route('contacts.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<th class="px-4 py-3">Status</th>', $html);

        $onRow = substr($html, strpos($html, 'data-contact-id="'.$on->id.'"'), 3000);
        $this->assertStringContainsString('bg-emerald-50', substr($onRow, 0, 120));
        $this->assertStringContainsString('>Active</span>', $onRow);
        $this->assertStringContainsString(route('contacts.deactivate', $on), $onRow);

        $offRow = substr($html, strpos($html, 'data-contact-id="'.$off->id.'"'), 3000);
        $this->assertStringNotContainsString('bg-emerald-50', substr($offRow, 0, 120));
        $this->assertStringContainsString('>Inactive</span>', $offRow);
        $this->assertStringContainsString(route('contacts.activate', $off), $offRow);
    }

    public function test_editing_a_hidden_contact_does_not_reshow_it(): void
    {
        $contact = $this->contact(active: false);

        $this->as($this->admin)
            ->patch(route('contacts.update', $contact), ['type' => Contact::TYPE_WHATSAPP, 'value' => '011 7240 3112', 'label' => 'Renamed'])
            ->assertRedirect(route('contacts.index'));

        $this->assertSame('Renamed', $contact->fresh()->label);
        $this->assertFalse($contact->fresh()->is_active);
    }

    public function test_the_switch_needs_the_edit_permission(): void
    {
        $contact = $this->contact();

        Permission::firstOrCreate(['name' => 'contact.view', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web'])->syncPermissions(['contact.view']);
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole('viewer');

        $this->as($viewer)->post(route('contacts.deactivate', $contact))->assertForbidden();
        $this->as($viewer)->post(route('contacts.activate', $contact))->assertForbidden();
        $this->assertTrue($contact->fresh()->is_active);
    }
}
