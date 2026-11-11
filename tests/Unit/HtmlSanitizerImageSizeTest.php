<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * A teacher can pick an image's size when uploading it into a material. That
 * size is the image's width and height attributes, so saving must keep them,
 * including on a large custom size that would once have been cut to 1200.
 */
class HtmlSanitizerImageSizeTest extends TestCase
{
    private const SRC = 'https://example.test/courses/1/media/materials/a1b2.webp';

    public function test_a_picked_width_survives_the_save(): void
    {
        $clean = HtmlSanitizer::clean('<p><img src="'.self::SRC.'" width="320"></p>');

        $this->assertStringContainsString('width="320"', $clean);
        $this->assertStringNotContainsString('height=', $clean);
    }

    public function test_a_picked_width_and_height_both_survive(): void
    {
        $clean = HtmlSanitizer::clean('<p><img src="'.self::SRC.'" width="400" height="150"></p>');

        $this->assertStringContainsString('width="400"', $clean);
        $this->assertStringContainsString('height="150"', $clean);
    }

    public function test_a_typed_height_keeps_its_inline_style(): void
    {
        $clean = HtmlSanitizer::clean('<p><img src="'.self::SRC.'" width="400" height="100" style="height: 100px"></p>');

        $this->assertMatchesRegularExpression('/style="height:\s*100px;?"/', $clean);
    }

    public function test_an_image_style_can_only_set_the_height(): void
    {
        $clean = HtmlSanitizer::clean('<p><img src="'.self::SRC.'" style="position:fixed;top:0;width:9999px;height:50px"></p>');

        $this->assertStringNotContainsString('position', $clean);
        $this->assertStringNotContainsString('9999', $clean);
        $this->assertMatchesRegularExpression('/height:\s*50px/', $clean);
    }

    public function test_a_large_size_is_not_cut_on_one_side_only(): void
    {
        $clean = HtmlSanitizer::clean('<p><img src="'.self::SRC.'" width="1600" height="900"></p>');

        $this->assertStringContainsString('width="1600"', $clean);
        $this->assertStringContainsString('height="900"', $clean);
    }

    public function test_an_image_without_a_size_stays_without_one(): void
    {
        $clean = HtmlSanitizer::clean('<p><img src="'.self::SRC.'"></p>');

        $this->assertStringNotContainsString('width=', $clean);
        $this->assertStringNotContainsString('height=', $clean);
    }

    public function test_a_size_cannot_carry_anything_else(): void
    {
        $clean = HtmlSanitizer::clean('<p><img src="'.self::SRC.'" width="300&quot; onerror=&quot;alert(1)" style="width:900px"></p>');

        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('900px', $clean);
    }
}
