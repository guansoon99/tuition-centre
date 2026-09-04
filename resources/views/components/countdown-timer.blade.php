@props(['targetDate', 'title' => null])

{{-- Live countdown timer for a Material of type 'countdown'. Reads
     target_date and ticks every second on the client.

     The title is rendered here rather than by the caller so it sits inside
     the card with the clock it belongs to. --}}
<div class="rounded-md bg-gradient-to-br from-sky-400 via-indigo-500 to-purple-600 px-4 py-8 text-center text-white sm:px-6"
     x-data="{
         targetMs: new Date('{{ $targetDate->toIso8601String() }}').getTime(),
         nowMs: Date.now(),
         get diff() { return Math.max(0, this.targetMs - this.nowMs); },
         get days() { return Math.floor(this.diff / 86400000); },
         get hours() { return Math.floor((this.diff % 86400000) / 3600000); },
         get minutes() { return Math.floor((this.diff % 3600000) / 60000); },
         get seconds() { return Math.floor((this.diff % 60000) / 1000); },
         pad(n) { return String(n).padStart(2, '0'); },
     }"
     x-init="setInterval(() => { nowMs = Date.now(); }, 1000)">

    @if (filled($title))
        <h3 class="mb-6 text-xl font-bold leading-tight sm:text-3xl">{{ $title }}</h3>
    @endif

    @php
        // One box per unit rather than one per digit, so "06" reads as a
        // number instead of two tiles that happen to sit together.
        //
        // tabular-nums, not font-mono: the numerals should look like the rest
        // of the page, but this ticks every second and proportional digits
        // change width as they change value, so the whole row would twitch
        // once a second without it.
        $box = 'flex h-16 w-16 items-center justify-center rounded-xl bg-white text-3xl font-extrabold tabular-nums text-black shadow-sm sm:h-24 sm:w-24 sm:text-5xl';
        $label = 'mt-2 text-[10px] font-semibold uppercase tracking-wider text-white sm:text-sm';
        // Matched to the box height so the colon centres against the boxes
        // rather than against the boxes-plus-label column.
        $sep = 'flex h-16 items-center text-2xl font-bold text-white/70 sm:h-24 sm:text-4xl';
    @endphp

    <div class="flex flex-nowrap items-start justify-center gap-1.5 sm:gap-4">
        <div>
            <div class="{{ $box }}" x-text="pad(days)"></div>
            <p class="{{ $label }}">days</p>
        </div>
        <div class="{{ $sep }}">:</div>
        <div>
            <div class="{{ $box }}" x-text="pad(hours)"></div>
            <p class="{{ $label }}">hours</p>
        </div>
        <div class="{{ $sep }}">:</div>
        <div>
            <div class="{{ $box }}" x-text="pad(minutes)"></div>
            <p class="{{ $label }}">minutes</p>
        </div>
        <div class="{{ $sep }}">:</div>
        <div>
            <div class="{{ $box }}" x-text="pad(seconds)"></div>
            <p class="{{ $label }}">seconds</p>
        </div>
    </div>

    {{-- calendar.svg is a full-colour illustration, not a monochrome glyph,
         so it goes in as an <img> and keeps its own palette — no
         currentColor, and nothing to recolour on a theme change. --}}
    <div class="mt-8 inline-flex items-center gap-2 rounded-lg bg-white/20 px-4 py-2">
        <img src="{{ asset('images/icons/calendar.svg') }}" alt=""
             class="h-6 w-6 flex-shrink-0 sm:h-7 sm:w-7" />
        <span class="text-base font-semibold tabular-nums sm:text-xl">
            {{ $targetDate->format('Y-m-d H:i') }}
        </span>
    </div>
</div>
