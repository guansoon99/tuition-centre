@props(['section' => null, 'action', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Title</label>
        <input type="text" name="title" required
               value="{{ old('title', $section?->title) }}"
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
        @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">
            Description <span class="font-normal text-slate-600">(optional)</span>
        </label>
        <textarea name="description" rows="3"
                  class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">{{ old('description', $section?->description) }}</textarea>
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Sort order</label>
        <input type="number" name="sort_order" min="0"
               value="{{ old('sort_order', $section?->sort_order) }}"
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Status</label>
        {{-- A select always submits a value, so unlike a checkbox it needs no
             hidden companion to make "off" arrive. --}}
        @php $published = (bool) old('is_published', $section?->is_published ?? true); @endphp
        <select name="is_published"
                class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
            <option value="1" @selected($published)>Published</option>
            <option value="0" @selected(! $published)>Unpublished</option>
        </select>
        <p class="mt-1 text-xs text-slate-600">Unpublished sections are visible to staff only.</p>
    </div>

    <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="hidden" name="never_collapses" value="0">
        <input type="checkbox" name="never_collapses" value="1"
               @checked(old('never_collapses', $section?->never_collapses ?? false))
               class="rounded border-slate-300">
        Always open
    </label>

    <div class="flex gap-3">
        <a href="{{ url()->previous() }}"
           class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
            Cancel
        </a>
        <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
            {{ $section ? 'Save' : 'Create section' }}
        </button>
    </div>
</form>
