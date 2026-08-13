<?php

namespace App\Support;

/**
 * Images and video a teacher embeds in section/material rich text.
 *
 * Private, like everything else on PrivateFile — there is no URL that reaches
 * these directly. They are served by CourseMediaController, which checks the
 * caller can see the course first. Same guarantee the Moodle install this
 * replaces gives via pluginfile.php.
 *
 * One difference from PrivateFile: images ARE re-encoded to WebP. PrivateFile
 * refuses to touch bytes because a student's submitted work must come back
 * exactly as sent. That reasoning does not apply here — this is content the
 * school produced, and a teacher pasting an 8 MP phone photo into a lesson
 * should not ship 8 MP to every student who opens it.
 */
class CourseMedia extends PrivateFile
{
    protected static bool $compressImages = true;

    /**
     * Storage prefix for one course's embedded media.
     *
     * The course id lives in the path because it is the only place the
     * association is recorded. A file's link to its course otherwise exists
     * solely as a URL inside a Quill HTML body, which cannot be queried — so
     * without this there would be nothing to authorise against.
     *
     * Keyed on id, never slug: slugs change when a course is renamed, and that
     * would break every URL already saved into lesson text.
     */
    public static function folder(int $courseId): string
    {
        return "course-media/{$courseId}";
    }
}
