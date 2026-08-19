{{--
    How long is left before the deadline, as a live value.

    Just the value — the caller supplies whatever label suits its context, so
    this reads correctly both as a table cell and as a line under the date.

    Shared because it appears in more than one place now, and a hand-copied
    Alpine block is how two counters end up disagreeing.

    Expects: $material
--}}
@if (! $material->due_date)
    No due date
@elseif ($material->isPastDue())
    Submissions closed
@else
    <span x-data="{
            target: {{ $material->due_date->getTimestamp() * 1000 }},
            remaining: '',
            tick() {
                const diff = this.target - Date.now();
                if (diff <= 0) { this.remaining = 'Past due'; return; }
                const d = Math.floor(diff / 86400000);
                const h = Math.floor((diff % 86400000) / 3600000);
                const m = Math.floor((diff % 3600000) / 60000);
                this.remaining = (d > 0 ? d + 'd ' : '') + h + 'h ' + m + 'm';
            },
        }"
        x-init="tick(); setInterval(() => tick(), 30000)"
        x-text="remaining"></span>
@endif
