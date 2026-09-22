<?php

namespace Tests\Feature;

use App\Models\BannerSlide;
use App\Models\Contact;
use App\Models\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public homepage a guest sees at "/". Round 1: the layout with the data
 * the site already has. The hero is the uploaded banner posters themselves,
 * shown as they are; the rest is fixed copy until it becomes editable.
 */
class PublicHomepageTest extends TestCase
{
    use RefreshDatabase;

    private function page(): string
    {
        return $this->get('/')->assertOk()->getContent();
    }

    public function test_the_page_carries_every_section(): void
    {
        $html = $this->page();

        foreach (['id="top"', 'id="about"', 'id="reviews"', 'id="contact"'] as $anchor) {
            $this->assertStringContainsString($anchor, $html);
        }
        // Login is reachable from the header and from the strip at least.
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'href="'.route('login').'"'));
    }

    public function test_the_header_button_reads_student_login_with_a_person_icon(): void
    {
        $html = $this->page();

        $button = substr($html, strpos($html, 'data-student-login'), 700);

        $this->assertStringContainsString('Student Login', $button);
        $this->assertStringContainsString('<svg', $button, 'The person icon sits in the button.');
        // On a phone the button must not wrap and the brand steps down a size.
        $this->assertStringContainsString('whitespace-nowrap', $button);
        $header = substr($html, strpos($html, '<header'), strpos($html, '</header>') - strpos($html, '<header'));
        $this->assertStringContainsString('<span class="sm:hidden">', $header);
        $this->assertStringContainsString('<span class="hidden sm:inline-flex">', $header);
        $this->assertSame(2, substr_count($header, \App\Models\SiteSettings::current()->displayName()), 'The name once per size variant.');
        $this->assertStringContainsString('href="'.route('login').'"', substr($html, strpos($html, 'data-student-login') - 80, 100));
    }

    public function test_the_hero_is_the_uploaded_posters_with_nothing_written_over_them(): void
    {
        BannerSlide::create(['image_path' => 'banner-slides/a.jpg', 'title' => 'SLIDE_ONE', 'sort_order' => 1, 'is_active' => true]);
        BannerSlide::create(['image_path' => 'banner-slides/b.jpg', 'title' => 'SLIDE_TWO', 'sort_order' => 2, 'is_active' => true]);
        BannerSlide::create(['image_path' => 'banner-slides/c.jpg', 'title' => 'SLIDE_OFF', 'sort_order' => 3, 'is_active' => false]);

        $html = $this->page();

        $hero = substr($html, strpos($html, 'id="top"'), strpos($html, 'id="about"') - strpos($html, 'id="top"'));

        $this->assertStringContainsString('alt="SLIDE_ONE"', $hero);
        $this->assertStringContainsString('alt="SLIDE_TWO"', $hero);
        $this->assertStringNotContainsString('SLIDE_OFF', $hero);
        $this->assertLessThan(strpos($hero, 'SLIDE_TWO'), strpos($hero, 'SLIDE_ONE'), 'Slides keep their sort order.');
        // The poster is the message: no headline, tagline or button on top of it.
        $this->assertStringNotContainsString('<h1', $hero);
        $this->assertStringNotContainsString('Login to Oster', $hero);
        $this->assertStringNotContainsString('Selamat datang', $hero);
        // Two slides: arrows and dots to move between them.
        $this->assertStringContainsString('aria-label="Next slide"', $hero);
        $this->assertStringContainsString('aria-label="Slide 2"', $hero);
    }

    public function test_the_hero_spans_the_full_width_and_the_rest_lines_up_with_it(): void
    {
        BannerSlide::create(['image_path' => 'banner-slides/a.jpg', 'title' => 'WIDE', 'sort_order' => 1, 'is_active' => true]);

        $html = $this->page();
        $hero = substr($html, strpos($html, 'id="top"'), strpos($html, 'id="about"') - strpos($html, 'id="top"'));

        // No centred column, no side padding, no rounded frame around the poster.
        $this->assertStringNotContainsString('max-w-', $hero);
        $this->assertStringNotContainsString('px-5', $hero);
        $this->assertStringNotContainsString('rounded-3xl', $hero);
        // And no height cap: the poster is as tall as its width makes it.
        $this->assertStringNotContainsString('max-h-', $hero);
        // Header, sections and footer are full width too, on one padding scale.
        $this->assertStringNotContainsString('max-w-6xl', $html);
        $this->assertGreaterThanOrEqual(5, substr_count($html, 'px-5 py-'), 'Header, three sections and the footer share the padding scale.');
    }

    public function test_long_words_in_cards_and_reviews_are_allowed_to_wrap(): void
    {
        \App\Models\HomepageBlock::create(['key' => 'features', 'data' => ['items' => [
            ['icon' => 'book', 'image' => '', 'title' => 'Supercalifragilisticexpialidocious_and_then_some', 'text' => 'https://example.com/a/very/long/unbroken/path/that/would/otherwise/overflow'],
        ]]]);
        \App\Models\HomepageBlock::create(['key' => 'reviews', 'data' => ['heading' => 'R', 'items' => [
            ['name' => 'Averyveryveryverylongsinglewordname', 'stars' => 5, 'image' => '', 'quote' => "Line one.
Line two after a break."],
        ]]]);
        \App\Support\HomepageContent::forgetCache();

        $html = $this->page();

        // The text column may shrink and its words may break.
        $this->assertStringContainsString('<div class="min-w-0 flex-1">', $html);
        $this->assertStringContainsString('class="break-words text-base font-semibold text-slate-900">Supercalifragilisticexpialidocious_and_then_some<', $html);
        $this->assertStringContainsString('whitespace-pre-line break-words text-sm text-slate-600">https://example.com/a/very/long', $html);
        $this->assertStringContainsString('class="break-words font-semibold text-slate-900">Averyveryveryverylongsinglewordname<', $html);
        // A plain-text quote from before the rich editor keeps its line break.
        $this->assertMatchesRegularExpression('/Line one\.<br>\s*Line two after a break\./', $html);
    }

    public function test_a_single_poster_shows_without_arrows(): void
    {
        BannerSlide::create(['image_path' => 'banner-slides/a.jpg', 'title' => 'ONLY_ONE', 'sort_order' => 1, 'is_active' => true]);

        $html = $this->page();

        $this->assertStringContainsString('alt="ONLY_ONE"', $html);
        $this->assertStringNotContainsString('aria-label="Next slide"', $html);
    }

    public function test_without_posters_the_hero_is_a_placeholder_with_a_login_button(): void
    {
        SiteSettings::row()->update(['name' => 'Qin: STPM Pengajian Am']);
        SiteSettings::forgetCache();

        $this->get('/')
            ->assertOk()
            ->assertSee('Selamat datang')
            ->assertSee('Qin: STPM Pengajian Am')
            ->assertSee('Login to Oster');
    }

    public function test_the_feature_cards_and_reviews_are_present(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Systematic Classes', 'Complete Notes', 'Recordings', 'Exam Resources'])
            ->assertSee('What Our Students Say')
            ->assertSee('Already a student?');
    }

    public function test_a_rich_text_quote_keeps_its_styling_and_loses_anything_unsafe(): void
    {
        \App\Models\HomepageBlock::create(['key' => 'reviews', 'data' => ['heading' => 'R', 'items' => [
            ['name' => 'Aina', 'stars' => 5, 'image' => '', 'quote' => '<p class="ql-align-center">Very <strong style="color: rgb(230, 0, 0);">patient</strong> teacher.</p><script>alert(1)</script>'],
        ]]]);
        \App\Support\HomepageContent::forgetCache();

        $html = $this->page();

        $this->assertStringContainsString('<p class="ql-align-center">Very <strong style="color:rgb(230,0,0);">patient</strong> teacher.</p>', $html);
        // The page has scripts of its own; the injected one must be gone.
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    public function test_long_reviews_are_cut_to_four_lines_with_a_more_toggle(): void
    {
        $html = $this->page();

        $reviews = substr($html, strpos($html, 'id="reviews"'), strpos($html, 'Already a student?') - strpos($html, 'id="reviews"'));

        // Every quote starts clamped, with a More/Less button the page shows
        // only when the text really overflows four lines.
        $this->assertSame(3, substr_count($reviews, 'x-ref="quote"'));
        $this->assertSame(3, substr_count($reviews, 'review-quote mt-4 max-h-24 overflow-hidden'));
        // No fade mask: it washed out words, on short quotes too.
        $this->assertStringNotContainsString('mask-image', $reviews);
        $this->assertSame(3, substr_count($reviews, 'data-review-toggle'));
        $this->assertSame(3, substr_count($reviews, 'x-data="reviewCard()"'));
        $this->assertStringContainsString('x-show="clamped || open"', $reviews);
        $this->assertStringContainsString("x-text=\"open ? 'Show less' : 'Show more'\"", $reviews);
        $this->assertStringContainsString('window.reviewCard', $html);
    }

    public function test_the_reviews_section_fades_in_when_scrolled_into_view(): void
    {
        $html = $this->page();

        $section = substr($html, strpos($html, '<section id="reviews"'), 1200);

        $this->assertStringContainsString('opacity-0 translate-y-6 transition-all duration-[1500ms]', $section);
        $this->assertStringContainsString('IntersectionObserver', $section);
        // Object form: Alpine removes the hidden-state classes too, static or not.
        $this->assertStringContainsString("{ 'opacity-100 translate-y-0': shown, 'opacity-0 translate-y-6': ! shown }", $section);
        $this->assertStringContainsString('motion-reduce:opacity-100', $section);
    }

    public function test_the_student_reviews_line_is_bold(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('font-bold uppercase tracking-[0.3em] text-orange-600" data-reviews-eyebrow>Student Reviews<', $html);
    }

    public function test_feature_card_icons_have_no_background(): void
    {
        $html = $this->page();

        $about = substr($html, strpos($html, 'id="about"'), strpos($html, 'id="reviews"') - strpos($html, 'id="about"'));

        $this->assertStringContainsString('rounded-xl text-orange-600" aria-hidden="true">', $about);
        $this->assertStringNotContainsString('bg-amber-100', $about);
    }

    public function test_features_and_reviews_are_sideways_sliders_with_arrows(): void
    {
        $html = $this->page();

        foreach (['features', 'reviews'] as $slider) {
            $this->assertStringContainsString('data-slider="'.$slider.'"', $html);
            $this->assertStringContainsString('aria-label="More '.$slider.'"', $html);
            $this->assertStringContainsString('aria-label="Previous '.$slider.'"', $html);
        }
        // The arrows are always drawn; they grey out (disabled) at the ends
        // rather than vanish, so the control is discoverable.
        $this->assertSame(2, substr_count($html, ':disabled="! canPrev"'));
        $this->assertSame(2, substr_count($html, ':disabled="! canNext"'));
        $this->assertStringNotContainsString('x-show="canNext"', $html);
        // The tracks scroll sideways and snap to cards.
        $this->assertSame(2, substr_count($html, 'snap-x snap-mandatory'));
        $this->assertStringContainsString('window.homeSlider', $html);
    }

    /** The footer's contacts live with the homepage content, not in the Contact rows. */
    private function footerContacts(array $contacts): void
    {
        \App\Support\HomepageContent::save('footer', ['contacts' => $contacts]);
    }

    public function test_the_footer_lists_the_homepages_own_contacts_with_working_links(): void
    {
        $this->footerContacts([
            ['type' => Contact::TYPE_WHATSAPP, 'value' => '011 7240 3112', 'label' => ''],
            ['type' => Contact::TYPE_PHONE, 'value' => '03 1234 5678', 'label' => 'Office'],
        ]);
        // A Contact row (Settings > Contact) is for the logged-in pages only.
        Contact::create(['type' => Contact::TYPE_TELEGRAM, 'value' => '@hidden_one', 'label' => 'HIDDEN_CONTACT', 'sort_order' => 3, 'is_active' => true]);

        $html = $this->page();

        $this->assertStringContainsString('href="https://wa.me/01172403112"', $html);
        $this->assertStringContainsString('011 7240 3112', $html);
        $this->assertStringContainsString('href="tel:+0312345678"', $html);
        $this->assertStringContainsString('Office', $html);
        $this->assertStringNotContainsString('HIDDEN_CONTACT', $html);
        // Each type wears its built-in icon image from public/images/icons
        // (counted in the footer; the side buttons carry them too).
        $footer = substr($html, strpos($html, '<footer'));
        $this->assertStringContainsString('images/icons/whatsapp.webp', $footer);
        $this->assertStringContainsString('images/icons/phone.webp', $footer);
        $this->assertSame(2, substr_count($footer, 'data-contact-icon="built-in"'));
        // Copyright on the left, contacts on the right: within the footer the
        // copyright line comes first (the side buttons, earlier in the page,
        // carry the same link).
        $this->assertLessThan(strpos($footer, 'wa.me/01172403112'), strpos($footer, 'Hak cipta terpelihara'));
    }

    public function test_ticked_footer_contacts_float_at_the_side_of_the_homepage(): void
    {
        $this->footerContacts([
            ['type' => Contact::TYPE_WHATSAPP, 'value' => '011 7240 3112', 'label' => 'FLOATS', 'floating' => true],
            ['type' => Contact::TYPE_PHONE, 'value' => '03 1234 5678', 'label' => 'FOOTER_ONLY', 'floating' => false],
        ]);
        // A back-office Contact row never floats on the public homepage.
        Contact::create(['type' => Contact::TYPE_TELEGRAM, 'value' => '@office_only', 'label' => 'BACKOFFICE_CONTACT', 'sort_order' => 1, 'is_active' => true]);

        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'data-contact-floater'));
        $floater = substr($html, strpos($html, 'data-contact-floater'), strpos($html, '</div>', strpos($html, 'data-contact-floater')) - strpos($html, 'data-contact-floater'));
        $this->assertStringContainsString('wa.me/01172403112', $floater);
        $this->assertStringContainsString('aria-label="FLOATS"', $floater);
        $this->assertStringNotContainsString('tel:+0312345678', $floater, 'Unticked: footer only.');
        $this->assertStringNotContainsString('office_only', $floater);
        // Both are still in the footer.
        $this->assertStringContainsString('FOOTER_ONLY', $html);
    }

    public function test_a_contact_from_before_the_tick_existed_floats_until_unticked(): void
    {
        \App\Models\HomepageBlock::create(['key' => 'footer', 'data' => ['contacts' => [
            ['type' => Contact::TYPE_WHATSAPP, 'value' => '011 7240 3112', 'label' => 'OLD_ROW', 'icon' => ''],
        ]]]);
        \App\Support\HomepageContent::forgetCache();

        $this->assertCount(1, \App\Support\HomepageContent::floatingContacts());
        $this->assertStringContainsString('data-contact-floater', $this->page());
    }

    public function test_a_type_without_a_built_in_icon_file_keeps_its_glyph(): void
    {
        $this->footerContacts([['type' => Contact::TYPE_TELEGRAM, 'value' => '@qin', 'label' => 'Telegram us']]);

        $html = $this->page();

        $this->assertStringContainsString('href="https://t.me/qin"', $html);
        $this->assertStringNotContainsString('data-contact-icon', $html);
        $this->assertNull(Contact::builtInIconUrl(Contact::TYPE_TELEGRAM));
        $this->assertStringEndsWith('images/icons/whatsapp.webp', Contact::builtInIconUrl(Contact::TYPE_WHATSAPP));
    }

    public function test_the_footer_shows_address_hours_and_the_malay_copyright(): void
    {
        SiteSettings::row()->update([
            'name' => 'Qin: STPM Pengajian Am',
            'contact_address' => '12, Jalan Contoh, Ipoh',
            'contact_hours' => 'Mon–Sat 9am–6pm',
        ]);
        SiteSettings::forgetCache();

        $this->get('/')
            ->assertOk()
            ->assertSee('12, Jalan Contoh, Ipoh')
            ->assertSee('Mon–Sat 9am–6pm')
            ->assertSee('© '.date('Y').' Qin: STPM Pengajian Am. Hak cipta terpelihara.');
    }

    public function test_a_logged_in_user_still_gets_the_dashboard_not_the_homepage(): void
    {
        $user = \App\Models\User::factory()->create(['is_active' => true]);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $user->assignRole('student');

        $this->actingAs($user)->get('/')->assertOk()->assertDontSee('Already a student?');
    }
}
