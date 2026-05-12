<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BannerSlideRequest;
use App\Models\BannerSlide;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

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
        $data['image_path'] = $request->file('image')->store('banner-slides', 'public');
        $data['is_active'] = $request->boolean('is_active', true);

        unset($data['image']);

        BannerSlide::create($data);

        $this->forgetCache();

        return redirect()
            ->route('banner.index')
            ->with('status', 'Slide added.');
    }

    public function show(BannerSlide $slide): View
    {
        return view('admin.banner.show', ['slide' => $slide]);
    }

    public function edit(BannerSlide $slide): View
    {
        return view('admin.banner.edit', ['slide' => $slide]);
    }

    public function update(BannerSlideRequest $request, BannerSlide $slide): RedirectResponse
    {
        $data = $request->validated();

        if ($request->hasFile('image')) {
            if ($slide->image_path) {
                Storage::disk('public')->delete($slide->image_path);
            }
            $data['image_path'] = $request->file('image')->store('banner-slides', 'public');
        }

        $data['is_active'] = $request->boolean('is_active');

        unset($data['image']);

        $slide->update($data);

        $this->forgetCache();

        return redirect()
            ->route('banner.index')
            ->with('status', 'Slide updated.');
    }

    public function destroy(BannerSlide $slide): RedirectResponse
    {
        if ($slide->image_path) {
            Storage::disk('public')->delete($slide->image_path);
        }
        $slide->delete();

        $this->forgetCache();

        return redirect()
            ->route('banner.index')
            ->with('status', 'Slide deleted.');
    }

    private function forgetCache(): void
    {
        Cache::forget('public:banner_slides');
    }
}
