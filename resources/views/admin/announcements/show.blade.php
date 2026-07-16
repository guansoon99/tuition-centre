@extends('layouts.app')

@section('title', 'View Announcement')

@section('content')
    @php
        $now = now();
        $starts = $announcement->starts_at ? \Carbon\Carbon::parse($announcement->starts_at) : null;
        $ends = $announcement->ends_at ? \Carbon\Carbon::parse($announcement->ends_at) : null;
        $status = match (true) {
            $ends && $now->gt($ends) => 'expired',
            $starts && $now->lt($starts) => 'scheduled',
            default => 'active',
        };
        $statusStyles = [
            'active' => ['pill' => 'bg-emerald-100 text-emerald-700', 'dot' => 'bg-emerald-500', 'label' => 'Active'],
            'scheduled' => ['pill' => 'bg-amber-100 text-amber-700', 'dot' => 'bg-amber-500', 'label' => 'Scheduled'],
            'expired' => ['pill' => 'bg-slate-200 text-slate-700', 'dot' => 'bg-slate-500', 'label' => 'Expired'],
        ][$status];
    @endphp

    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-xl font-semibold text-slate-900">View Announcement</h1>
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusStyles['pill'] }}">
                    <span class="mr-1.5 h-1.5 w-1.5 rounded-full {{ $statusStyles['dot'] }}"></span>
                    {{ $statusStyles['label'] }}
                </span>
            </div>
        </div>

        <div class="space-y-4 rounded-lg border border-slate-200 bg-white p-5">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Title</p>
                <p class="mt-1 text-base font-medium text-slate-900">{{ $announcement->title }}</p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Message</p>
                <p class="mt-1 whitespace-pre-line text-sm leading-relaxed text-slate-800">{{ $announcement->body }}</p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Audience</p>
                <p class="mt-1 text-sm text-slate-800">{{ $announcement->audience_label ?: '—' }}</p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Start</p>
                    <p class="mt-1 font-mono text-sm text-slate-800">{{ $announcement->starts_at ?: '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">End</p>
                    <p class="mt-1 font-mono text-sm text-slate-800">{{ $announcement->ends_at ?: '—' }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 border-t border-slate-100 pt-4 sm:grid-cols-2">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Sent</p>
                    <p class="mt-1 font-mono text-sm text-slate-800">{{ $announcement->sent_at->format('Y-m-d H:i') }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Recipients</p>
                    <p class="mt-1 text-sm text-slate-800">{{ $announcement->recipients }}</p>
                </div>
            </div>
        </div>

        <div class="flex gap-3">
            @can('announcements.edit')
                <a href="{{ route('announcements.edit', $announcement->id) }}"
                   class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700">
                    Edit
                </a>
            @endcan
            <a href="{{ route('announcements.index') }}"
               class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">
                Back
            </a>
        </div>
    </div>
@endsection
