@extends('layouts.app')

@section('title', 'Send announcement')

@section('content')
    <div class="mx-auto max-w-6xl space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Send Announcement</h1>
        </div>

        <form method="POST" action="{{ route('announcements.store') }}"
              enctype="multipart/form-data"
              class="space-y-4 rounded-lg border border-slate-200 bg-white p-5">
            @csrf
            @include('admin.announcements._fields', ['mode' => 'create'])

            <div class="flex gap-3">
                <a href="{{ route('announcements.index') }}"
                   class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
                    Cancel
                </a>
                <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                    Send Announcement
                </button>
            </div>
        </form>
    </div>
@endsection

@push('head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.min.css">
    <style>
        /* Kill Tom Select's default wrapper border/padding — we style .ts-control instead. */
        .ts-wrapper { padding: 0 !important; border: 0 !important; background: transparent !important; }
        .ts-wrapper.single .ts-control,
        .ts-wrapper.single.input-active .ts-control {
            border: 1px solid rgb(203 213 225) !important;
            border-radius: 0.375rem;
            padding: 0.5rem 2rem 0.5rem 0.75rem;
            min-height: 0;
            font-size: 0.875rem;
            background: #fff;
            box-shadow: none;
            /* Native-select-style chevron */
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%2364748b' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 8px center;
            background-size: 18px;
        }
        .ts-wrapper.single.focus .ts-control {
            border-color: rgb(100 116 139) !important;
            box-shadow: 0 0 0 1px rgb(100 116 139);
        }
        .ts-wrapper.single .ts-control input { font-size: 0.875rem; }
        .ts-dropdown { font-size: 0.875rem; border-color: rgb(203 213 225); }
    </style>
@endpush

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            flatpickr('[data-flatpickr]', {
                enableTime: true,
                time_24hr: true,
                dateFormat: 'Y-m-d H:i',
                minuteIncrement: 5,
                allowInput: false,
                disableMobile: true,
            });
            document.querySelectorAll('[data-search-select]').forEach(el => {
                new TomSelect(el, { create: false, allowEmptyOption: true });
            });
        });
    </script>
@endpush
