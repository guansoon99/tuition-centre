@props(['targetDate'])

{{-- Live countdown timer for a Material of type 'countdown'. Reads
     target_date and ticks every second on the client. --}}
<div class="rounded-md bg-gradient-to-br from-sky-400 via-indigo-500 to-purple-600 p-6 text-center text-white"
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

    @php
        $cell = 'inline-flex h-8 w-5 items-center justify-center rounded bg-slate-900/80 font-mono text-base font-semibold sm:h-12 sm:w-9 sm:text-2xl';
        $sep = 'pb-6 text-base font-semibold text-white/60 sm:text-2xl';
        $label = 'mt-1 text-[10px] uppercase tracking-wider text-white/80 sm:text-sm';
    @endphp

    <div class="flex flex-nowrap items-end justify-center gap-2 sm:flex-wrap sm:gap-4">
        <div class="text-center">
            <div class="flex gap-1">
                <span class="{{ $cell }}" x-text="pad(days).charAt(0)"></span>
                <span class="{{ $cell }}" x-text="pad(days).charAt(1)"></span>
            </div>
            <p class="{{ $label }}">days</p>
        </div>
        <span class="{{ $sep }}">:</span>
        <div class="text-center">
            <div class="flex gap-1">
                <span class="{{ $cell }}" x-text="pad(hours).charAt(0)"></span>
                <span class="{{ $cell }}" x-text="pad(hours).charAt(1)"></span>
            </div>
            <p class="{{ $label }}">hours</p>
        </div>
        <span class="{{ $sep }}">:</span>
        <div class="text-center">
            <div class="flex gap-1">
                <span class="{{ $cell }}" x-text="pad(minutes).charAt(0)"></span>
                <span class="{{ $cell }}" x-text="pad(minutes).charAt(1)"></span>
            </div>
            <p class="{{ $label }}">minutes</p>
        </div>
        <span class="{{ $sep }}">:</span>
        <div class="text-center">
            <div class="flex gap-1">
                <span class="{{ $cell }}" x-text="pad(seconds).charAt(0)"></span>
                <span class="{{ $cell }}" x-text="pad(seconds).charAt(1)"></span>
            </div>
            <p class="{{ $label }}">seconds</p>
        </div>
    </div>

    <p class="mt-4 font-mono text-base text-white/90 sm:text-lg">
        {{ $targetDate->format('Y-m-d H:i') }}
    </p>
</div>
