<?php

namespace Tests\Feature\Admin;

use App\Models\Contact;
use App\Models\HomepageBlock;
use App\Models\SiteSettings;
use App\Models\User;
use App\Support\HomepageContent;
use App\Support\PermissionCatalog;
use App\Support\PublicFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The homepage edited on the homepage: /homepage/edit renders the visitor's
 * page with an Edit button on each block, and each block saves as JSON to
 * its own URL. What is saved is what the public page shows next.
 */
class HomepageEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private User $viewer;

    private User $nobody;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['homepage.view', 'homepage.edit'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'webmaster', 'guard_name' => 'web'])->syncPermissions(['homepage.view', 'homepage.edit']);
        Role::firstOrCreate(['name' => 'reviewer', 'guard_name' => 'web'])->syncPermissions(['homepage.view']);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);

        $this->editor = User::factory()->create(['is_active' => true]);
        $this->editor->assignRole('webmaster');

        $this->viewer = User::factory()->create(['is_active' => true]);
        $this->viewer->assignRole('reviewer');

        $this->nobody = User::factory()->create(['is_active' => true]);
        $this->nobody->assignRole('teacher');
    }

    /** Log in as $user with a clean session (a second actor in one test). */
    private function as(User $user): static
    {
        $this->asGuest();

        return $this->actingAs($user);
    }

    /** Drop to a guest with a clean session. */
    private function asGuest(): static
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this;
    }

    /** @param  array<string,mixed>  $data */
    private function saveBlock(string $block, array $data)
    {
        return $this->as($this->editor)->putJson(route('homepage.update', $block), $data);
    }

    public function test_the_permissions_are_offered_in_their_own_group_view_first(): void
    {
        $this->assertSame(['homepage.view' => 'View', 'homepage.edit' => 'Edit'], PermissionCatalog::GROUPS['Homepage']);
    }

    public function test_a_viewer_sees_the_page_but_none_of_the_controls_and_cannot_save(): void
    {
        $html = $this->as($this->viewer)->get(route('homepage.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('Systematic Classes', $html);
        // Inside the admin layout: the sidebar is there, no bar at the bottom.
        $this->assertStringContainsString('<aside', $html);
        $this->assertStringNotContainsString('Back to admin', $html);
        $this->assertStringNotContainsString('data-homepage-editor', $html);
        $this->assertStringNotContainsString('aria-label="Edit feature cards"', $html);
        $this->assertStringNotContainsString('data-editing', $html);

        $this->as($this->viewer)
            ->putJson(route('homepage.update', 'cta'), ['heading' => 'x', 'button' => 'y'])
            ->assertForbidden();
        $this->as($this->viewer)
            ->post(route('homepage.upload-image'), ['image' => UploadedFile::fake()->image('x.png')])
            ->assertForbidden();
    }

    public function test_edit_alone_still_opens_the_page_with_the_controls(): void
    {
        Role::findByName('webmaster', 'web')->syncPermissions(['homepage.edit']);

        $html = $this->as($this->editor)->get(route('homepage.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('data-homepage-editor', $html);
        $this->assertStringContainsString('<aside', $html);
    }

    public function test_the_settings_sidebar_reads_banner_contact_homepage_announcement_website_settings(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->as($admin)->get('/')
            ->assertOk()
            ->assertSeeInOrder([
                route('banner.index'),
                route('contacts.index'),
                route('homepage.edit'),
                route('announcements.index'),
                route('settings.show'),
            ]);
    }

    public function test_the_editor_is_for_permission_holders_only(): void
    {
        $this->asGuest()->get(route('homepage.edit'))->assertRedirect(route('login'));
        $this->as($this->nobody)->get(route('homepage.edit'))->assertForbidden();
        $this->as($this->editor)->get(route('homepage.edit'))->assertOk();

        $this->as($this->nobody)
            ->putJson(route('homepage.update', 'cta'), ['heading' => 'x', 'button' => 'y'])
            ->assertForbidden();
    }

    public function test_the_editor_is_the_public_page_plus_edit_buttons(): void
    {
        $html = $this->actingAs($this->editor)->get(route('homepage.edit'))->assertOk()->getContent();

        // The visitor's content is all there...
        $this->assertStringContainsString('Systematic Classes', $html);
        $this->assertStringContainsString('What Our Students Say', $html);
        $this->assertStringContainsString('Already a student?', $html);
        // ...with an Edit button per block, the editor panel, and the bar.
        foreach (['Edit feature cards', 'Edit student reviews', 'Edit the already-a-student strip', 'Edit footer'] as $label) {
            $this->assertStringContainsString('aria-label="'.$label.'"', $html);
        }
        $this->assertStringContainsString('data-homepage-editor', $html);
        $this->assertStringContainsString('data-editing', $html);
        // ...inside the admin layout, with the public header and footer in
        // the page and no bar pinned to the bottom.
        $this->assertStringContainsString('<aside', $html);
        $this->assertStringContainsString('Hak cipta terpelihara', $html);
        $this->assertStringNotContainsString('Back to admin', $html);
        $this->assertStringNotContainsString('Editing the homepage.', $html);
        // @js() hands the editor its URL map inside JSON.parse('...'), so the
        // slashes come out escaped twice over; strip every backslash to compare.
        $this->assertStringContainsString(route('homepage.update', 'features'), str_replace(chr(92), '', $html));
    }

    public function test_the_public_page_has_none_of_the_edit_chrome(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-homepage-editor', $html);
        $this->assertStringNotContainsString('aria-label="Edit feature cards"', $html);
        $this->assertStringNotContainsString('data-editing', $html);
        $this->assertStringNotContainsString('<aside', $html, 'Visitors never get the admin sidebar.');
    }

    public function test_saved_feature_cards_replace_the_defaults_on_the_public_page(): void
    {
        $this->saveBlock('features', ['items' => [
            ['icon' => 'cap', 'title' => 'Small Classes', 'text' => 'Never more than twelve.'],
            ['icon' => 'star', 'title' => 'Weekly Tests', 'text' => 'Every Saturday.'],
        ]])->assertOk()->assertJson(['ok' => true]);

        $this->asGuest()->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Small Classes', 'Weekly Tests'])
            ->assertDontSee('Systematic Classes');

        $this->assertCount(2, HomepageBlock::find('features')->data['items']);
    }

    public function test_reviews_cta_and_footer_save_and_show(): void
    {
        SiteSettings::row()->update(['name' => 'Qin: STPM Pengajian Am']);
        SiteSettings::forgetCache();

        $this->saveBlock('reviews', [
            'eyebrow' => 'Testimoni',
            'heading' => 'Kata pelajar kami',
            'subheading' => 'From {name}',
            'items' => [['name' => 'Aina', 'stars' => 4, 'quote' => 'Terbaik!']],
        ])->assertOk();
        $this->saveBlock('cta', ['heading' => 'Sudah mendaftar?', 'text' => '', 'button' => 'Log masuk'])->assertOk();
        $this->saveBlock('footer', ['copyright' => '© {year} {name}. All rights reserved.'])->assertOk();

        $html = $this->asGuest()->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Kata pelajar kami', $html);
        $this->assertStringContainsString('From Qin: STPM Pengajian Am', $html);
        $this->assertStringContainsString('Aina', $html);
        $this->assertStringContainsString('aria-label="4 out of 5 stars"', $html);
        $this->assertStringContainsString('Sudah mendaftar?', $html);
        $this->assertStringContainsString('Log masuk', $html);
        $this->assertStringContainsString('© '.date('Y').' Qin: STPM Pengajian Am. All rights reserved.', $html);
        $this->assertStringNotContainsString('Hak cipta terpelihara', $html);
    }

    public function test_a_card_can_wear_an_uploaded_image_instead_of_an_icon(): void
    {
        Storage::fake(PublicFile::disk());

        $upload = $this->as($this->editor)
            ->post(route('homepage.upload-image'), ['image' => UploadedFile::fake()->image('logo.png', 300, 300)])
            ->assertOk()
            ->json();

        $this->assertMatchesRegularExpression('#^homepage/images/[A-Za-z0-9._-]+$#', $upload['path']);
        $this->assertNotEmpty($upload['url']);
        Storage::disk(PublicFile::disk())->assertExists($upload['path']);

        $this->saveBlock('features', ['items' => [
            ['icon' => 'book', 'image' => $upload['path'], 'title' => 'With Picture', 'text' => 'x'],
            ['icon' => 'star', 'image' => '', 'title' => 'With Icon', 'text' => 'y'],
        ]])->assertOk();

        $html = $this->asGuest()->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-card-image'), 'Exactly one card shows an image.');
        $this->assertStringContainsString($upload['url'], $html);
        $this->assertLessThan(strpos($html, 'With Icon'), strpos($html, 'data-card-image'), 'The image belongs to the first card.');

        // The editor previews it.
        $items = HomepageContent::forEditor()['features']['items'];
        $this->assertSame($upload['url'], $items[0]['image_url']);
        $this->assertSame('', $items[1]['image_url']);
    }

    public function test_a_review_shows_its_photo_or_else_the_first_letter_of_the_name(): void
    {
        Storage::fake(PublicFile::disk());
        $photo = $this->as($this->editor)->post(route('homepage.upload-image'), ['image' => UploadedFile::fake()->image('aina.jpg', 200, 200)])->json();

        $this->saveBlock('reviews', [
            'heading' => 'Reviews',
            'items' => [
                ['name' => 'Aina', 'stars' => 5, 'image' => $photo['path'], 'quote' => 'Terbaik!'],
                ['name' => 'Bala', 'stars' => 4, 'image' => '', 'quote' => 'Bagus.'],
            ],
        ])->assertOk();

        $html = $this->asGuest()->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-review-photo'), 'Only the review with a photo shows one.');
        $this->assertStringContainsString($photo['url'], $html);
        $this->assertStringContainsString('>B</span>', $html, 'The other review shows the initial.');
        $this->assertLessThan(strpos($html, 'Bala'), strpos($html, 'data-review-photo'));

        $items = HomepageContent::forEditor()['reviews']['items'];
        $this->assertSame($photo['url'], $items[0]['image_url']);
        $this->assertSame('', $items[1]['image_url']);
    }

    public function test_an_image_a_card_drops_is_deleted_from_the_disk(): void
    {
        Storage::fake(PublicFile::disk());
        $first = $this->as($this->editor)->post(route('homepage.upload-image'), ['image' => UploadedFile::fake()->image('a.png', 64, 64)])->json('path');
        $second = $this->as($this->editor)->post(route('homepage.upload-image'), ['image' => UploadedFile::fake()->image('b.png', 64, 64)])->json('path');

        $this->saveBlock('features', ['items' => [['icon' => 'book', 'image' => $first, 'title' => 'T', 'text' => 'x']]])->assertOk();
        Storage::disk(PublicFile::disk())->assertExists($first);

        // Replaced: the old file goes, the new one stays.
        $this->saveBlock('features', ['items' => [['icon' => 'book', 'image' => $second, 'title' => 'T', 'text' => 'x']]])->assertOk();
        Storage::disk(PublicFile::disk())->assertMissing($first);
        Storage::disk(PublicFile::disk())->assertExists($second);

        // Removed: gone too.
        $this->saveBlock('features', ['items' => [['icon' => 'book', 'image' => '', 'title' => 'T', 'text' => 'x']]])->assertOk();
        Storage::disk(PublicFile::disk())->assertMissing($second);
    }

    public function test_card_image_uploads_are_gated_and_checked(): void
    {
        Storage::fake(PublicFile::disk());

        $this->as($this->nobody)
            ->post(route('homepage.upload-image'), ['image' => UploadedFile::fake()->image('x.png')])
            ->assertForbidden();

        $this->as($this->editor)
            ->postJson(route('homepage.upload-image'), ['image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);

        // A path outside the upload folder is refused on save.
        $this->saveBlock('features', ['items' => [['icon' => 'book', 'image' => 'banner-slides/other.jpg', 'title' => 'T', 'text' => 'x']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.image']);
        $this->saveBlock('features', ['items' => [['icon' => 'book', 'image' => '../../.env', 'title' => 'T', 'text' => 'x']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.image']);
    }

    public function test_the_footer_edits_copyright_address_hours_and_its_own_contacts_as_one(): void
    {
        // A Contact row under Settings > Contact: it feeds the floating
        // buttons on logged-in pages and has nothing to do with the footer.
        $admin = Contact::create(['type' => 'telegram', 'value' => '@office_only', 'label' => 'BACKOFFICE_CONTACT', 'sort_order' => 1, 'is_active' => true]);

        $this->saveBlock('footer', [
            'copyright' => '© {year} Qin.',
            'address' => '12, Jalan Contoh, Ipoh',
            'hours' => 'Mon–Sat 9am–6pm',
            'contacts' => [
                ['type' => 'whatsapp', 'value' => '011 7240 3112', 'label' => ''],
                ['type' => 'phone', 'value' => '03 1111 2222', 'label' => 'Front desk'],
            ],
        ])->assertOk();

        $html = $this->asGuest()->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('© '.date('Y').' Qin.', $html);
        $this->assertStringContainsString('12, Jalan Contoh, Ipoh', $html);
        $this->assertStringContainsString('Mon–Sat 9am–6pm', $html);
        $this->assertStringContainsString('href="https://wa.me/01172403112"', $html);
        $this->assertStringContainsString('href="tel:+0311112222"', $html);
        // Order as given: WhatsApp first, the phone second.
        $this->assertLessThan(strpos($html, 'Front desk'), strpos($html, 'wa.me/01172403112'));
        // The back-office contact is not on the homepage...
        $this->assertStringNotContainsString('BACKOFFICE_CONTACT', $html);
        $this->assertStringNotContainsString('t.me/office_only', $html);
        // ...and the homepage save left the Contact rows alone.
        $this->assertSame(1, Contact::count());
        $this->assertSame('BACKOFFICE_CONTACT', $admin->fresh()->label);

        // The floating buttons on a logged-in page show the Contact row, not
        // the footer's list.
        $inside = $this->as($this->editor)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('t.me/office_only', $inside);
        $this->assertStringNotContainsString('wa.me/01172403112', $inside);

        // Stored with the block; the editor reads it back in order.
        $contacts = HomepageContent::forEditor()['footer']['contacts'];
        $this->assertSame(['011 7240 3112', '03 1111 2222'], array_column($contacts, 'value'));
        $this->assertSame(['whatsapp', 'phone'], array_column($contacts, 'type'));
    }

    public function test_the_deploy_migration_copies_the_current_contacts_into_the_footer_once(): void
    {
        Contact::create(['type' => 'whatsapp', 'value' => '011 7240 3112', 'label' => '', 'icon_path' => 'homepage/images/wa.webp', 'sort_order' => 2, 'is_active' => true]);
        Contact::create(['type' => 'facebook', 'value' => 'qin.stpm', 'label' => 'Qin', 'sort_order' => 1, 'is_active' => true]);
        Contact::create(['type' => 'telegram', 'value' => '@off', 'label' => 'OFF', 'sort_order' => 3, 'is_active' => false]);

        $migration = require base_path('database/migrations/2026_09_22_130000_copy_contacts_into_the_homepage_footer.php');
        $migration->up();

        $contacts = HomepageContent::get('footer')['contacts'];
        $this->assertSame(['facebook', 'whatsapp'], array_column($contacts, 'type'), 'Active rows, in their order; the inactive one left out.');
        $this->assertSame('homepage/images/wa.webp', $contacts[1]['icon']);

        // Running it again changes nothing once the footer has contacts.
        HomepageContent::save('footer', ['contacts' => [['type' => 'phone', 'value' => '03 1111 2222', 'label' => '']]]);
        $migration->up();
        $this->assertSame(['phone'], array_column(HomepageContent::get('footer')['contacts'], 'type'));
    }

    public function test_a_copyright_only_save_keeps_the_footers_contacts(): void
    {
        $this->saveBlock('footer', ['copyright' => 'x', 'contacts' => [['type' => 'whatsapp', 'value' => '011 7240 3112', 'label' => '']]])->assertOk();
        $this->saveBlock('footer', ['copyright' => '© {year} Qin.'])->assertOk();

        $this->assertCount(1, HomepageContent::get('footer')['contacts']);
        $this->assertStringContainsString('wa.me/01172403112', $this->asGuest()->get('/')->getContent());
    }

    public function test_facebook_and_xhs_contacts_show_in_the_footer_and_can_wear_their_own_icon(): void
    {
        Storage::fake(PublicFile::disk());
        $icon = $this->as($this->editor)->post(route('homepage.upload-image'), ['image' => UploadedFile::fake()->image('fb.png', 64, 64)])->json();

        $this->saveBlock('footer', [
            'copyright' => 'x',
            'contacts' => [
                ['type' => 'facebook', 'value' => 'qin.stpm', 'label' => 'Qin: STPM Pengajian Am', 'icon' => $icon['path']],
                ['type' => 'xhs', 'value' => 'https://www.xiaohongshu.com/user/profile/abc123', 'label' => 'Qin | STPM', 'icon' => ''],
            ],
        ])->assertOk();

        // The footer, as a visitor.
        $html = $this->asGuest()->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('href="https://www.facebook.com/qin.stpm"', $html);
        $this->assertStringContainsString('href="https://www.xiaohongshu.com/user/profile/abc123"', $html);
        $this->assertSame(1, substr_count($html, 'data-contact-icon="uploaded"'), 'Only the Facebook button wears an uploaded icon.');
        $this->assertStringContainsString($icon['url'], $html);
        $this->assertSame(1, substr_count($html, 'data-contact-icon="built-in"'), 'The XHS button uses the built-in icon image.');
        $this->assertStringContainsString('images/icons/xhs.webp', $html);
        $this->assertStringNotContainsString('images/icons/facebook.webp', $html, 'The uploaded icon replaces the built-in one.');

        // The floating buttons on a logged-in page are a different list
        // (the Contact rows, none here), so they show none of this.
        $inside = $this->as($this->editor)->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('facebook.com/qin.stpm', $inside);
        $this->assertSame(0, Contact::count());

        // Stored with the block, previewed in the editor.
        $stored = HomepageContent::get('footer')['contacts'];
        $this->assertSame($icon['path'], $stored[0]['icon']);
        $contacts = HomepageContent::forEditor()['footer']['contacts'];
        $this->assertSame($icon['url'], $contacts[0]['icon_url']);
        $this->assertSame('', $contacts[1]['icon_url']);

        // Dropping the icon deletes the file; a path outside the folder is refused.
        $this->saveBlock('footer', ['copyright' => 'x', 'contacts' => [
            ['type' => 'facebook', 'value' => 'qin.stpm', 'label' => '', 'icon' => ''],
        ]])->assertOk();
        Storage::disk(PublicFile::disk())->assertMissing($icon['path']);
        $this->saveBlock('footer', ['copyright' => 'x', 'contacts' => [['type' => 'facebook', 'value' => 'x', 'icon' => 'banner-slides/no.jpg']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contacts.0.icon']);
    }

    public function test_the_footer_refuses_an_unknown_contact_type(): void
    {
        $this->saveBlock('footer', ['copyright' => 'x', 'contacts' => [['type' => 'fax', 'value' => '1']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contacts.0.type']);

        $this->assertSame(0, Contact::count());
    }

    public function test_review_quotes_are_saved_as_cleaned_html(): void
    {
        $this->saveBlock('reviews', ['heading' => 'R', 'items' => [
            ['name' => 'Aina', 'stars' => 5, 'quote' => '<p>Great <em>notes</em>, <span style="color: rgb(0, 138, 0);">clear</span> lessons.</p><img src=x onerror="alert(1)">'],
        ]])->assertOk();

        $stored = HomepageBlock::find('reviews')->data['items'][0]['quote'];
        $this->assertStringContainsString('<em>notes</em>', $stored);
        $this->assertStringContainsString('color:rgb(0,138,0)', $stored);
        $this->assertStringNotContainsString('onerror', $stored);

        // The editor loads the rich-text bundle; the public page does not.
        $this->assertStringContainsString('quill', $this->as($this->editor)->get(route('homepage.edit'))->getContent());
        $this->assertStringNotContainsString('data-review-quill', $this->asGuest()->get('/')->getContent());
    }

    public function test_an_empty_looking_quote_is_refused(): void
    {
        $this->saveBlock('reviews', ['heading' => 'R', 'items' => [
            ['name' => 'Aina', 'stars' => 5, 'quote' => '<p><br></p>'],
        ]])->assertStatus(422)->assertJsonValidationErrors(['items.0.quote']);
    }

    public function test_an_empty_reviews_list_hides_the_section_for_visitors_but_not_the_editor(): void
    {
        $this->saveBlock('reviews', ['heading' => 'Reviews', 'items' => []])->assertOk();

        $this->asGuest()->get('/')->assertOk()->assertDontSee('id="reviews"', false);
        $this->as($this->editor)->get(route('homepage.edit'))
            ->assertOk()
            ->assertSee('id="reviews"', false)
            ->assertSee('No reviews yet');
    }

    public function test_bad_input_is_refused_with_field_errors(): void
    {
        $this->saveBlock('features', ['items' => [['icon' => 'book', 'title' => '', 'text' => 'x']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.title']);

        $this->saveBlock('features', ['items' => [['icon' => 'rocket', 'title' => 'x', 'text' => 'x']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.icon']);

        $this->saveBlock('reviews', ['heading' => 'x', 'items' => [['name' => 'A', 'stars' => 6, 'quote' => 'q']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.stars']);

        $tooMany = array_fill(0, 13, ['icon' => 'book', 'title' => 't', 'text' => 'x']);
        $this->saveBlock('features', ['items' => $tooMany])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        $this->saveBlock('nope', ['x' => 1])->assertNotFound();

        // Nothing above landed.
        $this->assertSame(0, HomepageBlock::count());
    }

    public function test_only_known_fields_are_stored(): void
    {
        $this->saveBlock('cta', ['heading' => 'H', 'text' => 'T', 'button' => 'B', 'evil' => '<script>'])->assertOk();

        // MySQL's JSON column stores object keys in its own order (SQLite keeps
        // the text as given), so compare with the keys sorted.
        $stored = HomepageBlock::find('cta')->data;
        ksort($stored);
        $this->assertSame(['button' => 'B', 'heading' => 'H', 'text' => 'T'], $stored);
    }

    public function test_the_sidebar_offers_the_page_to_viewers_and_editors_only(): void
    {
        $this->as($this->editor)->get('/')->assertOk()->assertSee(route('homepage.edit'));
        $this->as($this->viewer)->get('/')->assertOk()->assertSee(route('homepage.edit'));
        $this->as($this->nobody)->get('/')->assertOk()->assertDontSee(route('homepage.edit'));
    }

    public function test_defaults_survive_a_partial_stored_row(): void
    {
        // A row saved before a field existed: the new field falls back to
        // its default instead of erroring.
        HomepageBlock::create(['key' => 'reviews', 'data' => ['heading' => 'Only this']]);
        HomepageContent::forgetCache();

        $reviews = HomepageContent::get('reviews');

        $this->assertSame('Only this', $reviews['heading']);
        $this->assertSame('Student Reviews', $reviews['eyebrow']);
        $this->assertCount(3, $reviews['items']);
    }
}
