@props([
    'mode' => 'create',  // 'create' or 'edit'
    'announcement' => null,
    'courses' => collect(),
])

@php
    // On edit, the announcement's existing type governs which field renders.
    // On create, the radio pills toggle it. Alpine keeps them in sync.
    $initialType = old('type', $announcement?->type ?? \App\Models\Announcement::TYPE_TEXT);
@endphp

<div x-data="{ type: '{{ $initialType }}' }" class="space-y-4">
    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Title</label>
        <input type="text" name="title" required maxlength="120"
               value="{{ old('title', $announcement?->title) }}"
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
        @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Type</label>
        <div class="flex flex-wrap gap-2">
            @foreach (\App\Models\Announcement::TYPES as $val => $lbl)
                <label class="inline-flex cursor-pointer items-center rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-900 has-[:checked]:text-white">
                    <input type="radio" name="type" value="{{ $val }}"
                           x-model="type"
                           class="sr-only">
                    {{ $lbl }}
                </label>
            @endforeach
        </div>
        @error('type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    {{-- TEXT body — shown when type === 'text' --}}
    <div x-show="type === '{{ \App\Models\Announcement::TYPE_TEXT }}'" x-cloak>
        <label class="mb-1 block text-sm font-medium text-slate-700">Message</label>
        <textarea name="body" maxlength="2000" rows="4"
                  x-bind:required="type === '{{ \App\Models\Announcement::TYPE_TEXT }}'"
                  class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">{{ old('body', $announcement?->body) }}</textarea>
        @error('body') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    {{-- IMAGE upload — shown when type === 'image' --}}
    <div x-show="type === '{{ \App\Models\Announcement::TYPE_IMAGE }}'" x-cloak
         x-data="{ preview: null }">
        <label class="mb-1 block text-sm font-medium text-slate-700">Image</label>
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp"
               @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null"
               class="block w-full text-sm text-slate-700 file:mr-3 file:rounded file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:text-white" />

        <template x-if="preview">
            <div class="mt-3">
                <p class="mb-1 text-xs text-slate-500">New image preview</p>
                <img :src="preview" alt="" class="max-h-56 rounded border border-slate-200 object-contain" />
            </div>
        </template>

        @if ($announcement?->image_url)
            <div class="mt-3" x-show="!preview">
                <p class="mb-1 text-xs text-slate-500">Current image (upload a new one to replace)</p>
                <img src="{{ $announcement->image_url }}" alt="" class="max-h-56 rounded border border-slate-200 object-contain" />
            </div>
        @endif

        <p class="mt-1 text-xs text-slate-500">
            Recommended: <strong>1600×500</strong>, under 5 MB. JPG/PNG/WEBP.
        </p>
        @error('image') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Audience</label>
            <select name="audience" required class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option value="all" @selected(old('audience', $announcement?->audience ?? 'all') === 'all')>All</option>
                @foreach ($roles as $role)
                    <option value="{{ $role->name }}" @selected(old('audience', $announcement?->audience) === $role->name)>
                        {{ ucwords(strtolower(str_replace(['_', '-'], ' ', $role->name))) }}
                    </option>
                @endforeach
            </select>
            @error('audience') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Course</label>
            <select name="course_id" data-search-select
                    class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option value="">All</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected((int) old('course_id', $announcement?->course_id) === $course->id)>
                        {{ $course->code }} — {{ \Illuminate\Support\Str::limit($course->name, 40) }}
                    </option>
                @endforeach
            </select>
            @error('course_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {{-- Start defaults to now, so it is never blank and the list never
             shows a dash for it. End stays genuinely optional: empty there
             means the announcement runs until someone removes it. --}}
        <div class="min-w-0">
            <label class="mb-1 block text-sm font-medium text-slate-700">Start</label>
            <input type="text" name="starts_at" readonly size="1"
                   value="{{ old('starts_at', $announcement?->starts_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i')) }}"
                   data-flatpickr
                   class="w-full min-w-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono" />
            @error('starts_at') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="min-w-0">
            <label class="mb-1 block text-sm font-medium text-slate-700">
                End <span class="font-normal text-slate-600">(optional)</span>
            </label>
            <input type="text" name="ends_at" readonly size="1"
                   placeholder="Forever"
                   value="{{ old('ends_at', $announcement?->ends_at?->format('Y-m-d H:i')) }}"
                   data-flatpickr
                   class="w-full min-w-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono" />
            @error('ends_at') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>
</div>
