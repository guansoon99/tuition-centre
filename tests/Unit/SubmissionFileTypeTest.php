<?php

namespace Tests\Unit;

use App\Models\Material;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What counts as an acceptable submission.
 *
 * Word and PowerPoint cannot be identified from their bytes alone, which is
 * the whole reason this logic exists rather than a flat MIME whitelist:
 *
 *  - .docx/.pptx are ZIP archives. libmagic finds the "word/" or "ppt/" entry
 *    only near the start of the file and reports application/zip beyond that
 *    — verified against real files reading the WHOLE file, so this is not a
 *    matter of sniffing more bytes.
 *  - .doc/.ppt are OLE2 compound files and both report application/CDFV2.
 *
 * So the sniff establishes the container and the extension chooses the label.
 * These tests pin down exactly how far that trust extends.
 */
class SubmissionFileTypeTest extends TestCase
{
    /** SUBMISSION_MIME_TYPES is hand-written; it must match the type table. */
    public function test_the_mime_list_matches_the_type_table(): void
    {
        $this->assertSame(
            array_values(array_unique(array_values(Material::SUBMISSION_TYPES))),
            Material::SUBMISSION_MIME_TYPES,
            'SUBMISSION_MIME_TYPES has drifted from SUBMISSION_TYPES.',
        );
    }

    public function test_every_accepted_type_is_reachable_by_the_picker(): void
    {
        foreach (['pdf', 'jpg', 'png', 'webp', 'doc', 'docx', 'ppt', 'pptx'] as $ext) {
            $this->assertArrayHasKey($ext, Material::SUBMISSION_TYPES, "{$ext} is not accepted.");
        }
    }

    public static function accepted(): array
    {
        $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $pptx = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

        return [
            // Sniff names the type outright.
            'pdf'                 => ['application/pdf', 'pdf', 'application/pdf'],
            'jpeg'                => ['image/jpeg', 'jpg', 'image/jpeg'],
            'identified docx'     => [$docx, 'docx', $docx],
            'identified pptx'     => [$pptx, 'pptx', $pptx],
            'identified doc'      => ['application/msword', 'doc', 'application/msword'],
            // Sniff only reaches the container; the extension decides.
            'zip named docx'      => ['application/zip', 'docx', $docx],
            'zip named pptx'      => ['application/zip', 'pptx', $pptx],
            'ole2 named doc'      => ['application/CDFV2', 'doc', 'application/msword'],
            'ole2 named ppt'      => ['application/CDFV2', 'ppt', 'application/vnd.ms-powerpoint'],
            'ole2 alt spelling'   => ['application/x-ole-storage', 'doc', 'application/msword'],
            'uppercase extension' => ['application/zip', 'DOCX', $docx],
        ];
    }

    #[DataProvider('accepted')]
    public function test_accepted_combinations(?string $sniffed, string $ext, string $expected): void
    {
        $this->assertSame($expected, Material::resolveSubmissionMime($sniffed, $ext));
    }

    public static function rejected(): array
    {
        return [
            // The container must be the right KIND for the extension: a zip is
            // not a legacy .doc, and an OLE2 file is not a .docx.
            'zip named doc'        => ['application/zip', 'doc'],
            'zip named ppt'        => ['application/zip', 'ppt'],
            'ole2 named docx'      => ['application/CDFV2', 'docx'],
            // A container may never stand in for a non-Office type.
            'zip named pdf'        => ['application/zip', 'pdf'],
            'zip named png'        => ['application/zip', 'png'],
            // Extensions that are not on the list at all.
            'zip named zip'        => ['application/zip', 'zip'],
            'executable'           => ['application/x-dosexec', 'exe'],
            'php named docx'       => ['text/x-php', 'docx'],
            'html named docx'      => ['text/html', 'docx'],
            'script named pdf'     => ['text/x-php', 'pdf'],
            'svg named png'        => ['image/svg+xml', 'png'],
            'no extension'         => ['application/pdf', ''],
            'unsniffable'          => [null, 'docx'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_rejected_combinations(?string $sniffed, string $ext): void
    {
        $this->assertNull(Material::resolveSubmissionMime($sniffed, $ext));
    }

    /**
     * The proxied path validates with `mimetypes:` before resolving, so the
     * container types must survive that rule or a large .docx is rejected
     * before the resolver ever sees it.
     */
    public function test_the_validation_list_covers_containers_and_real_types(): void
    {
        $sniffable = Material::sniffableSubmissionMimeTypes();

        foreach (Material::SUBMISSION_MIME_TYPES as $mime) {
            $this->assertContains($mime, $sniffable);
        }
        foreach (['application/zip', 'application/CDFV2'] as $container) {
            $this->assertContains($container, $sniffable);
        }
    }
}
