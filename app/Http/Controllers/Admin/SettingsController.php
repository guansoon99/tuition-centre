<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsRequest;
use App\Models\SiteSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

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

        // Logo handling: a new file overrides remove_logo; otherwise remove_logo wins.
        if ($request->hasFile('logo')) {
            if ($settings->logo_path) {
                Storage::disk('public')->delete($settings->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('site', 'public');
        } elseif ($request->boolean('remove_logo') && $settings->logo_path) {
            Storage::disk('public')->delete($settings->logo_path);
            $data['logo_path'] = null;
        }

        $settings->update($data);

        SiteSettings::forgetCache();

        return redirect()
            ->route('settings.show')
            ->with('status', 'Settings saved.');
    }
}
