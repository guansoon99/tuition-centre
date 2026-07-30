@extends('layouts.app')

@section('title', 'Contacts')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold text-slate-900">Contacts</h1>
            @can('settings.edit')
                <a href="{{ route('contacts.create') }}"
                   class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                    + Add Contact
                </a>
            @endcan
        </div>

        @if ($contacts->isEmpty())
            <p class="rounded-md border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
                No contacts added yet. Add phone / WhatsApp / Telegram entries so parents and students can reach you.
            </p>
        @else
            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="w-full min-w-[720px] text-sm [&_td]:whitespace-nowrap [&_th]:whitespace-nowrap">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-800">
                        <tr>
                            <th class="px-2 py-3"></th>
                            <th class="px-4 py-3">Label</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Value</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100" data-sortable-contacts>
                        @foreach ($contacts as $contact)
                            <tr data-contact-id="{{ $contact->id }}">
                                <td class="px-2 py-3 text-center">
                                    <button type="button" title="Drag to reorder"
                                            class="contact-drag-handle inline-flex h-8 w-8 cursor-grab select-none items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-700 hover:bg-slate-200 active:cursor-grabbing">
                                        {{ $loop->iteration }}
                                    </button>
                                </td>
                                <td class="px-4 py-3 text-slate-700">
                                    {{ $contact->label ?: '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                                        {{ $contact->type_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-mono text-sm text-slate-800">
                                    {{ $contact->value }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        @can('settings.edit')
                                            <a href="{{ route('contacts.edit', $contact) }}"
                                               class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-emerald-700">
                                                Edit
                                            </a>
                                            <form method="POST" action="{{ route('contacts.destroy', $contact) }}"
                                                  onsubmit="return confirm('Delete this contact?');">
                                                @csrf @method('DELETE')
                                                <button type="submit"
                                                        class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-red-700">
                                                    Delete
                                                </button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const list = document.querySelector('[data-sortable-contacts]');
            if (!list) return;

            const renumber = () => {
                list.querySelectorAll('.contact-drag-handle')
                    .forEach((btn, i) => { btn.textContent = i + 1; });
            };

            Sortable.create(list, {
                handle: '.contact-drag-handle',
                animation: 150,
                ghostClass: 'opacity-40',
                onEnd: async () => {
                    renumber();
                    const ids = [...list.querySelectorAll('[data-contact-id]')]
                        .map(row => parseInt(row.dataset.contactId, 10));

                    try {
                        const res = await fetch('{{ route('contacts.reorder') }}', {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify({ ids }),
                        });
                        if (!res.ok) throw new Error('Save failed (' + res.status + ')');
                    } catch (e) {
                        alert('Could not save new order: ' + e.message);
                    }
                },
            });
        });
    </script>
@endpush
