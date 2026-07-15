@extends('layouts.app')

@section('title', 'Website Settings')

@section('content')
    <div class="mx-auto max-w-2xl space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Website Settings</h1>
        </div>

        <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf @method('PATCH')

            <fieldset class="space-y-4 rounded-md border border-slate-200 bg-white p-4">
                <legend class="px-2 text-sm font-medium">Branding</legend>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Centre name</label>
                    <input type="text" name="name" required
                           value="{{ old('name', $settings->name) }}"
                           placeholder="{{ config('app.name') }}"
                           class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Centre description</label>
                    <textarea name="description" rows="2" maxlength="500"
                              placeholder="STPM, SPM and pre-university tuition."
                              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">{{ old('description', $settings->description) }}</textarea>
                    <p class="mt-1 text-xs text-slate-500">Used as the public homepage meta description for SEO. 500 characters max.</p>
                    @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div x-data="{ preview: null, markedForRemoval: false }">
                    <label class="mb-1 block text-sm font-medium text-slate-700">Logo</label>

                    @if ($settings->logo_path)
                        {{-- Current logo (hidden once user has queued a removal or chosen a new file) --}}
                        <div x-show="!markedForRemoval && !preview"
                             class="mb-3 flex items-center justify-between gap-4 rounded border border-slate-200 bg-slate-50 p-3">
                            <img src="{{ $settings->logoUrl() }}" alt="" class="h-12" />
                            <button type="button"
                                    @click="markedForRemoval = true"
                                    class="text-xs font-medium text-red-600 hover:text-red-700">
                                Remove
                            </button>
                        </div>

                        {{-- Pending-removal banner (only shows once Remove was clicked, no new file yet) --}}
                        <div x-show="markedForRemoval && !preview" x-cloak
                             class="mb-3 flex items-center justify-between gap-4 rounded border border-red-200 bg-red-50 p-3">
                            <p class="text-xs text-red-700">Logo will be removed when you save.</p>
                            <button type="button"
                                    @click="markedForRemoval = false"
                                    class="text-xs font-medium text-slate-700 hover:text-slate-900">
                                Undo
                            </button>
                        </div>

                        {{-- Hidden field carries the intent to the controller. Cleared if user picks a new file. --}}
                        <input type="hidden" name="remove_logo" x-bind:value="markedForRemoval && !preview ? '1' : ''">
                    @endif

                    <input type="file" name="logo" accept="image/*"
                           @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null"
                           class="mt-2 block w-full text-sm text-slate-700 file:mr-3 file:rounded file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:text-white" />

                    <template x-if="preview">
                        <div class="mt-3 rounded border border-slate-200 bg-slate-50 p-3">
                            <p class="mb-2 text-xs text-slate-500">New logo preview</p>
                            <img :src="preview" alt="" class="h-12" />
                        </div>
                    </template>

                    <p class="mt-1 text-xs text-slate-500">PNG with transparent background recommended. Will be displayed at ~32–40px tall. Max 2 MB.</p>
                    @error('logo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </fieldset>

            <fieldset class="space-y-4 rounded-md border border-slate-200 bg-white p-4">
                <legend class="px-2 text-sm font-medium">Student access</legend>

                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" name="students_can_change_password" value="1"
                           @checked(old('students_can_change_password', $settings->students_can_change_password))
                           class="mt-0.5">
                    <span>
                        Allow students to change their own password
                        <span class="mt-0.5 block text-xs text-slate-500">
                            When off, students can still view their account page but the password change form is hidden.
                            Teachers and admins are not affected.
                        </span>
                    </span>
                </label>
            </fieldset>

            {{--
                Contact fieldset hidden because the public homepage no longer renders
                a contact section. Re-enable both together if you bring it back.

                <fieldset class="space-y-4 rounded-md border border-slate-200 bg-white p-4">
                    <legend class="px-2 text-sm font-medium">Contact (shown on public homepage)</legend>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Phone</label>
                        <input type="text" name="contact_phone" value="{{ old('contact_phone', $settings->contact_phone) }}"
                               placeholder="+60 1X-XXX XXXX"
                               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Address</label>
                        <textarea name="contact_address" rows="2"
                                  placeholder="Your address line, Malaysia"
                                  class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">{{ old('contact_address', $settings->contact_address) }}</textarea>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Hours</label>
                        <input type="text" name="contact_hours" value="{{ old('contact_hours', $settings->contact_hours) }}"
                               placeholder="Mon–Sat, 9am–9pm"
                               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                </fieldset>
            --}}

            <div>
                <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                    Save
                </button>
            </div>
        </form>

    </div>
@endsection
