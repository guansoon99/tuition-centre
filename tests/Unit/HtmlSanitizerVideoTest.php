<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * An uploaded lesson video is a <video src> with no inner content, which
 * the sanitiser's remove-empty pass took for an empty element and deleted
 * on save — every video a teacher embedded vanished, silently. The tag is
 * whitelisted; it also has to survive that pass.
 */
class HtmlSanitizerVideoTest extends TestCase
{
    private const SRC = 'https://example.test/courses/1/media/materials/a1b2.mp4';

    public function test_a_video_with_a_source_survives_the_save(): void
    {
        $clean = HtmlSanitizer::clean('<p><video controls src="'.self::SRC.'" preload="metadata"></video></p>');

        $this->assertStringContainsString('<video', $clean);
        $this->assertStringContainsString('src="'.self::SRC.'"', $clean);
        $this->assertStringContainsString('controls', $clean);
    }

    public function test_a_video_pointing_nowhere_is_still_dropped(): void
    {
        $this->assertStringNotContainsString('<video', HtmlSanitizer::clean('<p><video controls></video></p>'));
    }

    public function test_a_source_child_survives_too(): void
    {
        $clean = HtmlSanitizer::clean('<video controls><source src="'.self::SRC.'" type="video/mp4"></video>');

        $this->assertStringContainsString('<source src="'.self::SRC.'" type="video/mp4"', $clean);
    }

    public function test_the_style_attribute_the_editor_adds_is_not_needed(): void
    {
        // Sizing comes from CSS (Tailwind's preflight gives video max-width:
        // 100%), so the inline style being stripped is fine — and this pins
        // that the tag survives without it.
        $clean = HtmlSanitizer::clean('<p><video controls src="'.self::SRC.'" style="max-width:100%;"></video></p>');

        $this->assertStringContainsString('<video', $clean);
        $this->assertStringNotContainsString('style=', $clean);
    }
}
