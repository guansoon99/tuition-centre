@props(['course' => null, 'action', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="space-y-4">
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Code</label>
            <input type="text" name="code" required value="{{ old('code', $course?->code) }}"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono" />
            @error('code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Name</label>
            <input type="text" name="name" required value="{{ old('name', $course?->name) }}"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Description</label>
        <textarea name="description" rows="3"
                  class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">{{ old('description', $course?->description) }}</textarea>
    </div>

    <div x-data="{ preview: null }">
        <label class="mb-1 block text-sm font-medium text-slate-700">Image</label>
        <input type="file" name="banner_image" accept="image/*"
               @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null"
               class="block w-full text-sm text-slate-700 file:mr-3 file:rounded file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:text-white" />

        <template x-if="preview">
            <div class="mt-3">
                <p class="mb-1 text-xs text-slate-500">New banner preview</p>
                <img :src="preview" alt="" class="h-32 rounded border border-slate-200 object-cover" />
            </div>
        </template>

        @if ($course?->banner_image)
            <div class="mt-3" x-show="!preview">
                <p class="mb-1 text-xs text-slate-500">Current banner</p>
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($course->banner_image) }}"
                     alt="" class="h-32 rounded border border-slate-200 object-cover" />
            </div>
        @endif
    </div>

    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_active" value="1"
               @checked(old('is_active', $course?->is_active ?? true))>
        Active (visible to enrolled students)
    </label>

    <div class="flex gap-3">
        <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
            {{ $course ? 'Save changes' : 'Create course' }}
        </button>
        <a href="{{ route('courses.index') }}"
           class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
            Cancel
        </a>
    </div>
</form>
