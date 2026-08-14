<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsRequest;
use App\Models\SiteSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use App\Support\PublicFile;

class SettingsController extends Controller
{
    public function show(): View
    {
        return view('admin.settings.show', [
            'settings' => SiteSettings::current(),
        ]);
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        $settings = SiteSettings::current();

        $data = [
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'contact_phone' => $request->input('contact_phone'),
            'contact_address' => $request->input('contact_address'),
            'contact_hours' => $request->input('contact_hours'),
            'students_can_change_password' => $request->boolean('students_can_change_password'),
            'updated_at' => now(),
        ];

        /*
         * Logo handling: a new file overrides remove_logo; otherwise
         * remove_logo wins.
         *
         * The old file is noted here but deleted only after the row is saved.
         * Deleting first meant a failure storing the replacement — R2
         * unreachable, say — left the site with no logo and a row pointing at
         * a path that no longer existed.
         */
        $replaced = null;

        if ($request->hasFile('logo')) {
            $replaced = $settings->logo_path;
            $data['logo_path'] = PublicFile::store($request->file('logo'), 'site');
        } elseif ($request->boolean('remove_logo') && $settings->logo_path) {
            $replaced = $settings->logo_path;
            $data['logo_path'] = null;
        }

        $settings->update($data);

        // Nothing references it now. forget() is null-safe.
        PublicFile::forget($replaced);

        SiteSettings::forgetCache();

        return redirect()
            ->route('settings.show')
            ->with('status', 'Settings saved.');
    }
}
