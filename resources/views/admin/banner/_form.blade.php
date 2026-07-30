@props(['slide' => null, 'action', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="space-y-4">
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif

    <div x-data="{ preview: null }">
        <label class="mb-1 block text-sm font-medium text-slate-700">Image</label>
        <input type="file" name="image" accept="image/*" {{ $slide ? '' : 'required' }}
               @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null"
               class="block w-full text-sm text-slate-700 file:mr-3 file:rounded file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:text-white" />

        <template x-if="preview">
            <div class="mt-3">
                <p class="mb-1 text-xs text-slate-500">New image preview</p>
                <img :src="preview" alt="" class="h-40 rounded border border-slate-200 object-contain" />
            </div>
        </template>

        @if ($slide?->image_path)
            <div class="mt-3" x-show="!preview">
                <p class="mb-1 text-xs text-slate-500">Current image (upload a new one to replace)</p>
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($slide->image_path) }}"
                     alt="" class="h-40 rounded border border-slate-200 object-contain" />
            </div>
        @endif

        <p class="mt-1 text-xs text-slate-500">
            Recommended: <strong>1600×500</strong> (or your preferred wide ratio), under 5 MB. JPG/PNG/WEBP.
        </p>
        @error('image') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Title</label>
        <input type="text" name="title" required maxlength="255"
               value="{{ old('title', $slide?->title) }}"
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
        @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="flex gap-3">
        <a href="{{ route('banner.index') }}"
           class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
            Cancel
        </a>
        <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
            {{ $slide ? 'Save' : 'Upload' }}
        </button>
    </div>
</form>
