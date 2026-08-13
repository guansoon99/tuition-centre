<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Support\PrivateFile;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnnouncementImageController extends Controller
{
    /**
     * Stream an announcement's image.
     *
     * These live on the private disk, so there is no direct URL — this is the
     * only way to fetch one, and it authorises first. An announcement can be
     * scoped to a course or a role, so "logged in" is not sufficient: the
     * caller has to be in the audience.
     *
     * Visibility is delegated to User::visibleAnnouncements(), the same query
     * that decides which announcements appear on the home page. Reusing it
     * means the image gate and the listing can never disagree — a narrower
     * audience rule applies here automatically.
     */
    public function show(Request $request, Announcement $announcement): StreamedResponse
    {
        $user = $request->user();

        $canSee = $user->visibleAnnouncements()
            ->whereKey($announcement->getKey())
            ->exists();

        abort_unless($canSee, 403);

        if (! $announcement->image_path || ! PrivateFile::exists($announcement->image_path)) {
            abort(404);
        }

        $extension = pathinfo($announcement->image_path, PATHINFO_EXTENSION) ?: 'webp';

        // Inline: this is rendered in an <img> tag, not downloaded.
        return PrivateFile::response(
            $announcement->image_path,
            ($announcement->title ?: 'announcement').'.'.$extension,
            match (strtolower($extension)) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                default => 'image/webp',
            },
            'inline',
        );
    }
}
