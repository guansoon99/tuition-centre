<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BannerSlide;
use App\Support\HomepageContent;
use App\Support\PublicFile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The homepage, edited on the homepage. edit() renders the very same view
 * a visitor gets, with the editing chrome switched on: an "Edit" button on
 * each block that opens a panel with that block's fields. update() takes
 * one block's fields as JSON and the page reloads to show them.
 */
class HomepageController extends Controller
{
    public function edit(Request $request): View
    {
        // A viewer gets the page as visitors see it, with a bar saying so;
        // an editor gets the controls as well.
        $editing = $request->user()->can('homepage.edit');

        return view('public.home', [
            'slides' => BannerSlide::where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'content' => HomepageContent::all(),
            'editorContent' => $editing ? HomepageContent::forEditor() : null,
            'editing' => $editing,
            'backoffice' => true,
            'ownFloater' => true,
        ]);
    }

    /**
     * A picture for the page: a feature card's image or a reviewer's photo.
     * Stored on the public disk (re-encoded to WebP like every public
     * image) and handed back as a path the form keeps and a URL it
     * previews with. The path is only accepted by update() if it sits in
     * this folder.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'image.max' => 'The image must be under 2 MB.',
        ]);

        $path = PublicFile::store($request->file('image'), HomepageContent::IMAGE_FOLDER);

        return response()->json(['path' => $path, 'url' => PublicFile::url($path)]);
    }

    public function update(Request $request, string $block): JsonResponse
    {
        abort_unless(HomepageContent::isBlock($block), 404);

        $data = $request->validate(HomepageContent::rules($block));

        HomepageContent::save($block, $data);

        if ($block === 'footer') {
            HomepageContent::saveFooterExtras($data);
        }

        return response()->json(['ok' => true]);
    }
}
