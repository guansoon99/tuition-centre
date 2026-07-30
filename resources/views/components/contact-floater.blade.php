@php
    // Cache the active-contacts query so every page render doesn't hit the DB.
    // The ContactController invalidates 'public:contacts' on any CRUD change.
    $contacts = \Illuminate\Support\Facades\Cache::remember(
        'public:contacts',
        3600,
        fn () => \App\Models\Contact::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
    );
@endphp

@if ($contacts->isNotEmpty())
    <div class="fixed right-3 top-1/2 z-40 flex -translate-y-1/2 flex-col gap-2 sm:right-4">
        @foreach ($contacts as $contact)
            @php
                // Per-type styling.
                $style = match ($contact->type) {
                    \App\Models\Contact::TYPE_WHATSAPP => 'bg-[#25D366] hover:bg-[#1EBE5D]',
                    \App\Models\Contact::TYPE_TELEGRAM => 'bg-[#2AABEE] hover:bg-[#1E96D3]',
                    default => 'bg-slate-900 hover:bg-slate-800',
                };
            @endphp
            <a href="{{ $contact->url }}"
               @if ($contact->type !== \App\Models\Contact::TYPE_PHONE) target="_blank" rel="noopener" @endif
               title="{{ $contact->label ?: $contact->type_label.' — '.$contact->value }}"
               aria-label="{{ $contact->label ?: $contact->type_label }}"
               class="inline-flex h-11 w-11 items-center justify-center rounded-full text-white shadow-lg transition-transform hover:scale-110 {{ $style }}">
                @if ($contact->type === \App\Models\Contact::TYPE_WHATSAPP)
                    {{-- WhatsApp glyph --}}
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347zM12.05 21.785h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884zm8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                @elseif ($contact->type === \App\Models\Contact::TYPE_TELEGRAM)
                    {{-- Telegram glyph --}}
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69.01-.03.01-.14-.06-.2-.07-.06-.16-.04-.24-.02-.1.02-1.7 1.08-4.8 3.17-.45.31-.86.46-1.23.45-.4-.01-1.18-.23-1.76-.42-.71-.23-1.28-.35-1.23-.74.03-.2.31-.41.85-.62 3.32-1.44 5.53-2.4 6.64-2.87 3.16-1.32 3.83-1.55 4.26-1.55.09 0 .3.02.44.13.11.09.14.21.16.29-.01.06.01.24 0 .38z"/>
                    </svg>
                @else
                    {{-- Phone glyph --}}
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                    </svg>
                @endif
            </a>
        @endforeach
    </div>
@endif
