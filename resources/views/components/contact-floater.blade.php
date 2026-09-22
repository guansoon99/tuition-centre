{{-- The floating contact buttons at the side of the page.

     Fed either by the Contact rows under Settings > Contact (the logged-in
     pages: no `items`, cached, ContactController forgets 'public:contacts'
     on any change) or by a list handed in (the public homepage: its own
     contacts, those ticked "show at the side"). Both are reduced to the
     same shape here so the markup below has one story. --}}
@props(['items' => null])

@php
    $buttons = collect($items ?? \App\Models\Contact::activeCached())
        ->map(function ($c) {
            $isRow = $c instanceof \App\Models\Contact;
            $type = (string) ($isRow ? $c->type : ($c['type'] ?? ''));
            $value = (string) ($isRow ? $c->value : ($c['value'] ?? ''));
            $label = (string) ($isRow ? $c->label : ($c['label'] ?? ''));
            $icon = (string) ($isRow ? $c->icon_path : ($c['icon'] ?? ''));

            return [
                'type' => $type,
                'url' => \App\Models\Contact::linkFor($type, $value),
                'title' => $label !== '' ? $label : \App\Models\Contact::labelFor($type).' — '.$value,
                'aria' => $label !== '' ? $label : \App\Models\Contact::labelFor($type),
                'uploaded' => $icon !== '' ? \App\Support\PublicFile::url($icon) : null,
                'builtin' => \App\Models\Contact::builtInIconUrl($type),
            ];
        })
        ->filter(fn ($b) => $b['url'] !== null)
        ->values();
@endphp

@if ($buttons->isNotEmpty())
    <div class="fixed right-3 top-1/2 z-40 flex -translate-y-1/2 flex-col gap-2 sm:right-4" data-contact-floater>
        @foreach ($buttons as $b)
            @php
                // Per-type styling.
                $style = match ($b['type']) {
                    \App\Models\Contact::TYPE_WHATSAPP => 'bg-[#25D366] hover:bg-[#1EBE5D]',
                    \App\Models\Contact::TYPE_TELEGRAM => 'bg-[#2AABEE] hover:bg-[#1E96D3]',
                    \App\Models\Contact::TYPE_FACEBOOK => 'bg-[#1877F2] hover:bg-[#166FE5]',
                    \App\Models\Contact::TYPE_XHS => 'bg-[#FF2442] hover:bg-[#E51F3B]',
                    default => 'bg-slate-900 hover:bg-slate-800',
                };
            @endphp
            <a href="{{ $b['url'] }}"
               @if ($b['type'] !== \App\Models\Contact::TYPE_PHONE) target="_blank" rel="noopener" @endif
               title="{{ $b['title'] }}"
               aria-label="{{ $b['aria'] }}"
               class="inline-flex h-11 w-11 items-center justify-center overflow-hidden rounded-full text-white transition-transform hover:scale-110 {{ ($b['uploaded'] || $b['builtin']) ? '' : $style.' shadow-lg' }}">
                @if ($b['uploaded'])
                    {{-- The admin's own icon, uploaded from the homepage editor --}}
                    <img src="{{ $b['uploaded'] }}" alt="" class="h-full w-full object-cover" data-contact-icon="uploaded" />
                @elseif ($b['builtin'])
                    {{-- The built-in icon image for the type (public/images/icons) --}}
                    <img src="{{ $b['builtin'] }}" alt="" class="h-full w-full object-contain" data-contact-icon="built-in" />
                @elseif ($b['type'] === \App\Models\Contact::TYPE_FACEBOOK)
                    {{-- Facebook glyph --}}
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="''' + FB_PATH + '''"/></svg>
                @elseif ($b['type'] === \App\Models\Contact::TYPE_XHS)
                    <span class="text-[10px] font-extrabold tracking-wide" aria-hidden="true">XHS</span>
                @elseif ($b['type'] === \App\Models\Contact::TYPE_WHATSAPP)
                    {{-- WhatsApp glyph --}}
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347zM12.05 21.785h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884zm8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                @elseif ($b['type'] === \App\Models\Contact::TYPE_TELEGRAM)
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
