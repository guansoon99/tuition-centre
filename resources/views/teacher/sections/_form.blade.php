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
        <label class="mb-1 block text-sm font-medium text-slate-700">Description (optional)</label>
        <textarea name="description" rows="3"
                  class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">{{ old('description', $section?->description) }}</textarea>
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Sort order</label>
        <input type="number" name="sort_order" min="0"
               value="{{ old('sort_order', $section?->sort_order) }}"
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
    </div>

    <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="hidden" name="is_published" value="0">
        <input type="checkbox" name="is_published" value="1"
               @checked(old('is_published', $section?->is_published ?? true))
               class="rounded border-slate-300">
        Published (visible to students)
    </label>

    <div class="flex gap-3">
        <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
            {{ $section ? 'Save changes' : 'Create section' }}
        </button>
        <a href="{{ url()->previous() }}"
           class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
            Cancel
        </a>
    </div>
</form>
