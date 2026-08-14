<?php

namespace App\Http\Requests\Admin;

use App\Models\SiteSettings;

use Illuminate\Foundation\Http\FormRequest;

class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.edit') ?? false;
    }

    /** Whether saving as submitted would leave the site with no logo. */
    private function wouldLeaveNoLogo(): bool
    {
        $current = SiteSettings::current()->logo_path;

        return ! $current || $this->boolean('remove_logo');
    }

    public function messages(): array
    {
        return [
            'logo.required' => SiteSettings::current()->logo_path
                ? 'Choose a replacement logo — the site cannot be left without one.'
                : 'A logo is required.',
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            /*
             * The saved settings must always end up with a logo — it renders
             * on the login page, before there is a session to authorise
             * anything.
             *
             * That is a rule about the RESULT, not about the field. Removing
             * is still allowed; you simply cannot save the removal on its own.
             * So a file is required when the save would otherwise leave none:
             *
             *   no logo currently   -> must upload one
             *   Remove was clicked  -> must upload a replacement
             *   otherwise           -> optional, the existing one is kept
             *
             * Requiring it on every save would mean re-uploading the same
             * image just to change the centre name.
             */
            'logo' => [
                $this->wouldLeaveNoLogo() ? 'required' : 'nullable',
                'image', 'mimes:jpeg,jpg,png,webp', 'max:2048',
            ],
            'remove_logo' => ['nullable', 'boolean'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'contact_address' => ['nullable', 'string', 'max:500'],
            'contact_hours' => ['nullable', 'string', 'max:255'],
            'students_can_change_password' => ['nullable', 'boolean'],
        ];
    }
}
