<?php

namespace Tests\Unit;

use App\Models\Contact;
use PHPUnit\Framework\TestCase;

/**
 * Where each contact type links to. Facebook and Xiaohongshu take either a
 * full link, used as given, or a bare page name / profile id.
 */
class ContactLinkTest extends TestCase
{
    private function link(string $type, string $value): ?string
    {
        return (new Contact(['type' => $type, 'value' => $value]))->url;
    }

    public function test_facebook_and_xhs_are_offered_as_types(): void
    {
        $this->assertSame('Facebook', Contact::TYPES['facebook']);
        $this->assertSame('Xiaohongshu (XHS)', Contact::TYPES['xhs']);
    }

    public function test_a_full_link_is_used_as_given(): void
    {
        $this->assertSame('https://www.facebook.com/qin.stpm', $this->link('facebook', 'https://www.facebook.com/qin.stpm'));
        $this->assertSame('http://xhslink.com/abc', $this->link('xhs', 'http://xhslink.com/abc'));
    }

    public function test_a_bare_name_or_id_goes_on_the_profile_url(): void
    {
        $this->assertSame('https://www.facebook.com/qinstpm', $this->link('facebook', 'qinstpm'));
        $this->assertSame('https://www.facebook.com/qinstpm', $this->link('facebook', '@qinstpm'));
        $this->assertSame('https://www.xiaohongshu.com/user/profile/5f1a2b3c', $this->link('xhs', '5f1a2b3c'));
    }

    public function test_the_older_types_are_unchanged(): void
    {
        $this->assertSame('https://wa.me/01172403112', $this->link('whatsapp', '011 7240 3112'));
        $this->assertSame('tel:+0312345678', $this->link('phone', '03 1234 5678'));
        $this->assertSame('https://t.me/qin', $this->link('telegram', '@qin'));
    }

    public function test_an_empty_value_has_no_link(): void
    {
        $this->assertNull($this->link('facebook', ''));
        $this->assertNull($this->link('xhs', '  '));
    }
}
