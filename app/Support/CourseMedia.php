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
 * Extends PrivateImage rather than PrivateFile, so images are downscaled and
 * re-encoded to WebP: a teacher pasting an 8 MP phone photo into a lesson
 * should not ship 8 MP to every student who opens it. Video is untouched —
 * compression only applies to formats StoredFile::shouldCompress accepts.
 */
class CourseMedia extends PrivateImage
{

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

    /**
     * Filenames of course media embedded in a rich-text body.
     *
     * Matches on the /media/<file> portion rather than the whole URL, so a
     * change of domain does not stop these being recognised — which would
     * otherwise make every embedded file look unreferenced at once.
     */
    public static function filenamesIn(?string $html): array
    {
        if (! $html) {
            return [];
        }

        preg_match_all('#/media/([A-Za-z0-9\-]+\.[A-Za-z0-9]+)#', $html, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Delete the given files unless some other material still embeds them.
     *
     * The same file can legitimately appear in two lessons — a teacher
     * copy-pastes a diagram between materials and both bodies reference one
     * object. Deleting on the first material's removal would blank the image
     * in the second, so every candidate is checked against the rest first.
     *
     * $exceptMaterialIds are the materials being deleted or edited: their own
     * (old) bodies must not count as references to themselves. This matters
     * more than it looks — the lookup below is withTrashed, so a material that
     * has just been soft-deleted still counts unless it is named here, and
     * nothing would ever be purged.
     */
    public static function purgeUnreferenced(int $courseId, array $filenames, array|int|null $exceptMaterialIds = null): int
    {
        $except = array_filter((array) $exceptMaterialIds);

        if ($filenames === []) {
            return 0;
        }

        $stillUsed = [];

        // Course banners live in this same folder but are referenced by a
        // column, not by any lesson body. Nothing currently routes a banner
        // filename into $filenames — they are UUIDs and never appear in text —
        // but if one ever did, the body scan below would not see it and the
        // banner would be deleted. Same blind spot the orphan sweep had.
        \App\Models\Course::withTrashed()
            ->whereNotNull('banner_image')
            ->pluck('banner_image')
            ->each(function ($path) use (&$stillUsed) {
                $stillUsed[basename($path)] = true;
            });

        // withTrashed: a soft-deleted material can still be force-deleted
        // later, and until then its body is the only record of what it used.
        \App\Models\Material::withTrashed()
            ->whereNotNull('body')
            ->when($except !== [], fn ($q) => $q->whereKeyNot($except))
            ->select('id', 'body')
            ->chunkById(500, function ($rows) use (&$stillUsed) {
                foreach ($rows as $row) {
                    foreach (static::filenamesIn($row->body) as $name) {
                        $stillUsed[$name] = true;
                    }
                }
            });

        $deleted = 0;

        foreach ($filenames as $name) {
            if (isset($stillUsed[$name])) {
                continue;
            }

            static::forget(static::folder($courseId).'/'.$name);
            $deleted++;
        }

        return $deleted;
    }
}
