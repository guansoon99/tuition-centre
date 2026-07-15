@extends('layouts.app')

@section('title', 'Edit Announcement')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <a href="{{ route('announcements.index') }}" class="text-xs text-slate-500 hover:underline">&larr; All announcements</a>
            <h1 class="mt-2 text-xl font-semibold text-slate-900">Edit Announcement</h1>
        </div>

        <form method="POST" action="{{ route('announcements.update', $announcement->id) }}"
              class="space-y-4 rounded-lg border border-slate-200 bg-white p-5">
            @csrf @method('PATCH')
            @include('admin.announcements._fields', ['mode' => 'edit', 'announcement' => $announcement])

            <div class="flex gap-3">
                <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                    Save
                </button>
                <a href="{{ route('announcements.index') }}"
                   class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
                    Cancel
                </a>
            </div>
        </form>
    </div>
@endsection

@push('head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
@endpush

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            flatpickr('[data-flatpickr]', {
                enableTime: true,
                time_24hr: true,
                dateFormat: 'Y-m-d H:i',
                minuteIncrement: 5,
                allowInput: false,
            });
        });
    </script>
@endpush
