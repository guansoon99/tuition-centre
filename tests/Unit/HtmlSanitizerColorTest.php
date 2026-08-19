<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Text colour has to survive the sanitizer.
 *
 * The bug this locks down: the whitelist allowed style= on <span> only, but
 * Quill does not always use a span. When the text already carries an inline
 * format, Quill puts the colour on THAT element — bold red text comes through
 * as <strong style="color:…">, so the sanitizer kept the bold and quietly threw
 * the colour away. Plain coloured text worked, which is why it looked like
 * colour "worked except on bold".
 *
 * Every string below is verbatim Quill 2.0.2 output captured from a browser,
 * not hand-written approximations — the whole bug was a wrong assumption about
 * what Quill emits.
 */
class HtmlSanitizerColorTest extends TestCase
{
    public static function colouredMarkup(): array
    {
        return [
            'bold'            => ['<p><strong style="color: rgb(230, 0, 0);">red</strong></p>'],
            'bold highlight'  => ['<p><strong style="background-color: rgb(255, 255, 0);">hl</strong></p>'],
            'italic'          => ['<p><em style="color: rgb(230, 0, 0);">red</em></p>'],
            'underline'       => ['<p><u style="color: rgb(230, 0, 0);">red</u></p>'],
            'strike'          => ['<p><s style="color: rgb(230, 0, 0);">red</s></p>'],
            'inline code'     => ['<p><code style="color: rgb(230, 0, 0);">red</code></p>'],
            'bold + italic'   => ['<p><strong style="color: rgb(230, 0, 0);"><em>red</em></strong></p>'],
            'link'            => ['<p><a href="https://x.com" style="color: rgb(230, 0, 0);">red</a></p>'],
            'plain span'      => ['<p><span style="color: rgb(230, 0, 0);">red</span></p>'],
        ];
    }

    #[DataProvider('colouredMarkup')]
    public function test_colour_survives_sanitising(string $html): void
    {
        $this->assertStringContainsString(
            'color:',
            HtmlSanitizer::clean($html),
            'The colour was stripped — check HTML.Allowed permits [style] on this tag.',
        );
    }

    public static function dangerousMarkup(): array
    {
        return [
            'positioning'  => ['<p><strong style="position: fixed; top: 0;">x</strong></p>', 'position'],
            'url()'        => ['<p><strong style="background-color: url(javascript:alert(1));">x</strong></p>', 'url('],
            'expression()' => ['<p><em style="width: expression(alert(1));">x</em></p>', 'expression'],
            'display'      => ['<p><u style="display: none;">x</u></p>', 'display'],
            'behavior'     => ['<p><s style="behavior: url(evil.htc);">x</s></p>', 'behavior'],
            'event handler'=> ['<p><strong onclick="alert(1)">x</strong></p>', 'onclick'],
            'js: href'     => ['<p><a href="javascript:alert(1)">x</a></p>', 'javascript:'],
        ];
    }

    /**
     * Widening the whitelist to [style] must not widen what a style may contain.
     */
    #[DataProvider('dangerousMarkup')]
    public function test_dangerous_css_is_still_stripped(string $html, string $needle): void
    {
        $this->assertStringNotContainsStringIgnoringCase(
            $needle,
            HtmlSanitizer::clean($html),
            "{$needle} survived — CSS.AllowedProperties is no longer holding.",
        );
    }
}
