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

    /** The course banner. One file, replaced in place. */
    public static function bannerFolder(int $courseId): string
    {
        return static::folder($courseId).'/banners';
    }

    /**
     * Teaching material: PDFs, and the images and video embedded in lesson
     * text.
     *
     * Flat rather than nested per material, deliberately. The Quill editor
     * uploads while a resource is still being composed — before it has been
     * saved and therefore before it has an id — so there is nothing to nest
     * under at the moment the file arrives.
     */
    public static function materialsFolder(int $courseId): string
    {
        return static::folder($courseId).'/materials';
    }

    /**
     * One student's work on one assignment.
     *
     * Nested, unlike materials, because here the ids all exist by the time
     * anything is uploaded — and because this path is load-bearing: the
     * register step refuses any object key that does not sit under the
     * uploader's own prefix, which is what stops one student claiming
     * another's file.
     */
    public static function assignmentFolder(int $courseId, int $materialId, int $userId): string
    {
        return static::folder($courseId)."/assignments/{$materialId}/{$userId}";
    }

    /** Subfolders reachable through CourseMediaController. */
    public const SERVED_FOLDERS = ['banners', 'materials'];

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

        // The optional folder segment covers both the current
        // /media/materials/<file> shape and the older /media/<file> one, so a
        // body saved before the restructure still counts as a reference.
        preg_match_all('#/media/(?:[a-z-]+/)?([A-Za-z0-9\-]+\.[A-Za-z0-9]+)#', $html, $m);

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

            // Embedded media lives in materials/, not at the course root.
            static::forget(static::materialsFolder($courseId).'/'.$name);
            $deleted++;
        }

        return $deleted;
    }
}
