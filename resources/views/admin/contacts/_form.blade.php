@props(['contact' => null, 'action', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Label</label>
        <input type="text" name="label" required maxlength="100"
               placeholder="e.g. Main office, STPM inquiries"
               value="{{ old('label', $contact?->label) }}"
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
        @error('label') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div x-data="{ type: '{{ old('type', $contact?->type ?? 'phone') }}' }" class="space-y-4">
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Type</label>
            <div class="flex flex-wrap gap-2">
                @foreach (\App\Models\Contact::TYPES as $val => $label)
                    <label class="inline-flex cursor-pointer items-center rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-900 has-[:checked]:text-white">
                        <input type="radio" name="type" value="{{ $val }}"
                               x-model="type"
                               class="sr-only">
                        {{ $label }}
                    </label>
                @endforeach
            </div>
            @error('type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Value</label>
            <input type="text" name="value" required maxlength="100"
                   :placeholder="type === 'telegram' ? 'e.g. @myhandle' : 'e.g. 60123456789'"
                   value="{{ old('value', $contact?->value) }}"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            @error('value') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="flex gap-3">
        <a href="{{ route('contacts.index') }}"
           class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
            Cancel
        </a>
        <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
            {{ $contact ? 'Save' : 'Add contact' }}
        </button>
    </div>
</form>
