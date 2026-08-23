<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The optional email on the user form.
 *
 * The column already existed — nullable and UNIQUE — but nothing on the admin
 * form wrote to it. That combination is the whole reason these tests are here:
 * "optional" and "unique" only coexist if an empty box becomes NULL. Store ''
 * instead and the first blank save works while the *second* one dies on the
 * database constraint, which is a horrible way to find out.
 */
class UserEmailFieldTest extends TestCase
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'username' => 'new_person',
            'name' => 'New Person',
            'role' => 'student',
            'password' => 'longpassword',
            'password_confirmation' => 'longpassword',
        ], $overrides);
    }

    // ---- Creating -----------------------------------------------------------

    public function test_an_email_is_saved_when_one_is_given(): void
    {
        $this->actingAs($this->admin)
            ->post('/users', $this->payload(['email' => 'parent@example.test']))
            ->assertRedirect('/users');

        $this->assertSame(
            'parent@example.test',
            User::where('username', 'new_person')->value('email'),
        );
    }

    /** Optional means optional: no email, no complaint. */
    public function test_a_user_can_be_created_without_an_email(): void
    {
        $this->actingAs($this->admin)
            ->post('/users', $this->payload(['email' => '']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/users');

        $created = User::where('username', 'new_person')->firstOrFail();

        $this->assertNull($created->email, 'A blank box must land as NULL.');
        $this->assertNotSame('', $created->email, "Storing '' would break the next blank save.");
    }

    /**
     * The constraint trap, head on.
     *
     * Two rows may both hold NULL under a UNIQUE index. Two rows holding ''
     * may not. This fails loudly if the normalisation is ever removed.
     */
    public function test_two_users_can_both_be_created_with_no_email(): void
    {
        $this->actingAs($this->admin)
            ->post('/users', $this->payload(['username' => 'first_blank', 'email' => '']))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post('/users', $this->payload(['username' => 'second_blank', 'email' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, User::whereIn('username', ['first_blank', 'second_blank'])->count());
        $this->assertSame(0, User::whereIn('username', ['first_blank', 'second_blank'])
            ->whereNotNull('email')->count());
    }

    /** A box holding only spaces is an empty box. */
    public function test_a_whitespace_only_email_is_treated_as_blank(): void
    {
        $this->actingAs($this->admin)
            ->post('/users', $this->payload(['email' => '   ']))
            ->assertSessionHasNoErrors();

        $this->assertNull(User::where('username', 'new_person')->value('email'));
    }

    public function test_a_malformed_email_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/users', $this->payload(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');

        $this->assertNull(User::where('username', 'new_person')->first());
    }

    public function test_an_email_already_in_use_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->actingAs($this->admin)
            ->post('/users', $this->payload(['email' => 'taken@example.test']))
            ->assertSessionHasErrors('email');

        $this->assertNull(User::where('username', 'new_person')->first());
    }

    /**
     * A soft-deleted account keeps its email, so the address stays taken.
     *
     * That is the documented trade-off of soft deletes on this table (see the
     * 2026_08_13 migration). Validation has to agree with the database here —
     * if the rule looked only at live rows it would pass, then the INSERT
     * would fail on the constraint.
     */
    public function test_a_soft_deleted_users_email_is_still_taken(): void
    {
        $gone = User::factory()->create(['email' => 'archived@example.test']);
        $gone->delete();

        $this->assertSoftDeleted('users', ['id' => $gone->id]);

        $this->actingAs($this->admin)
            ->post('/users', $this->payload(['email' => 'archived@example.test']))
            ->assertSessionHasErrors('email');
    }

    // ---- Editing ------------------------------------------------------------

    public function test_an_email_can_be_added_to_an_existing_user(): void
    {
        $student = User::factory()->create(['email' => null, 'username' => 'edit_me']);
        $student->assignRole('student');

        $this->actingAs($this->admin)
            ->patch("/users/{$student->id}", [
                'username' => 'edit_me',
                'name' => $student->name,
                'email' => 'added@example.test',
                'role' => 'student',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('added@example.test', $student->fresh()->email);
    }

    public function test_clearing_the_email_stores_null(): void
    {
        $student = User::factory()->create(['email' => 'remove@example.test', 'username' => 'clear_me']);
        $student->assignRole('student');

        $this->actingAs($this->admin)
            ->patch("/users/{$student->id}", [
                'username' => 'clear_me',
                'name' => $student->name,
                'email' => '',
                'role' => 'student',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($student->fresh()->email);
    }

    /** Editing other fields must not trip the unique rule on your own address. */
    public function test_a_user_can_keep_their_own_email_while_editing(): void
    {
        $student = User::factory()->create(['email' => 'mine@example.test', 'username' => 'keep_me']);
        $student->assignRole('student');

        $this->actingAs($this->admin)
            ->patch("/users/{$student->id}", [
                'username' => 'keep_me',
                'name' => 'Renamed Person',
                'email' => 'mine@example.test',
                'role' => 'student',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $student->fresh();
        $this->assertSame('Renamed Person', $fresh->name);
        $this->assertSame('mine@example.test', $fresh->email);
    }

    public function test_an_email_belonging_to_someone_else_is_rejected_on_edit(): void
    {
        User::factory()->create(['email' => 'someone@example.test']);
        $student = User::factory()->create(['email' => null, 'username' => 'thief']);
        $student->assignRole('student');

        $this->actingAs($this->admin)
            ->patch("/users/{$student->id}", [
                'username' => 'thief',
                'name' => $student->name,
                'email' => 'someone@example.test',
                'role' => 'student',
            ])
            ->assertSessionHasErrors('email');

        $this->assertNull($student->fresh()->email);
    }

    // ---- The pages ----------------------------------------------------------

    public function test_the_create_form_offers_the_field_as_optional(): void
    {
        $this->actingAs($this->admin)->get('/users/create')
            ->assertOk()
            ->assertSee('name="email"', false)
            ->assertSee('Email');
    }

    public function test_the_edit_form_is_prefilled_and_the_detail_page_shows_it(): void
    {
        $student = User::factory()->create(['email' => 'shown@example.test']);
        $student->assignRole('student');

        $this->actingAs($this->admin)->get("/users/{$student->id}/edit")
            ->assertOk()
            ->assertSee('shown@example.test', false);

        $this->actingAs($this->admin)->get("/users/{$student->id}")
            ->assertOk()
            ->assertSee('shown@example.test', false);
    }
}
