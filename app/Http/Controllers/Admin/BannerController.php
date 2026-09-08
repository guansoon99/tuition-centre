<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BannerSlideRequest;
use App\Models\BannerSlide;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Support\PublicFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BannerController extends Controller
{
    public function index(): View
    {
        return view('admin.banner.index', [
            'slides' => BannerSlide::orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.banner.create');
    }

    public function store(BannerSlideRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['image_path'] = PublicFile::store($request->file('image'), 'banner-slides');
        $data['is_active'] = true; // New slides start visible; Deactivate on the list hides one without deleting it.
        // Auto-append to the end of the existing order.
        $data['sort_order'] = (int) BannerSlide::max('sort_order') + 1;

        unset($data['image']);

        BannerSlide::create($data);

        $this->forgetCache();

        return redirect()
            ->route('banner.index')
            ->with('status', 'Slide added.');
    }

    public function edit(BannerSlide $slide): View
    {
        return view('admin.banner.edit', ['slide' => $slide]);
    }

    public function update(BannerSlideRequest $request, BannerSlide $slide): RedirectResponse
    {
        $data = $request->validated();

        // Old image deleted after the row is saved, not before — see the note
        // in SettingsController::update.
        $replaced = null;

        if ($request->hasFile('image')) {
            $replaced = $slide->image_path;
            $data['image_path'] = PublicFile::store($request->file('image'), 'banner-slides');
        }

        // Status changes only through activate()/deactivate(). An edit must
        // not quietly re-show a slide somebody hid.
        unset($data['image'], $data['is_active']);

        $slide->update($data);

        PublicFile::forget($replaced);

        $this->forgetCache();

        return redirect()
            ->route('banner.index')
            ->with('status', 'Slide updated.');
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        // Only reorder rows that actually exist — guards against a crafted
        // payload with non-existent IDs.
        $valid = BannerSlide::whereIn('id', $data['ids'])->pluck('id')->all();

        DB::transaction(function () use ($data, $valid) {
            $order = 1;
            foreach ($data['ids'] as $id) {
                if (! in_array((int) $id, $valid, true)) {
                    continue;
                }
                BannerSlide::where('id', $id)->update(['sort_order' => $order++]);
            }
        });

        $this->forgetCache();

        return response()->json(['ok' => true, 'count' => count($valid)]);
    }

    public function destroy(BannerSlide $slide): RedirectResponse
    {
        if ($slide->image_path) {
            PublicFile::forget($slide->image_path);
        }
        $slide->delete();

        $this->forgetCache();

        return redirect()
            ->route('banner.index')
            ->with('status', 'Slide deleted.');
    }

    public function deactivate(BannerSlide $slide): RedirectResponse
    {
        return $this->setActive($slide, false, 'Slide deactivated. It no longer shows on the homepage.');
    }

    public function activate(BannerSlide $slide): RedirectResponse
    {
        return $this->setActive($slide, true, 'Slide activated.');
    }

    /**
     * Hide or show a slide without deleting it. The homepage reads active
     * slides from a cache, so the flip has to clear it or the change waits
     * up to five minutes to appear.
     */
    private function setActive(BannerSlide $slide, bool $active, string $status): RedirectResponse
    {
        $slide->update(['is_active' => $active]);

        $this->forgetCache();

        return redirect()
            ->route('banner.index')
            ->with('status', $status);
    }

    private function forgetCache(): void
    {
        Cache::forget('public:banner_slides');
    }
}
