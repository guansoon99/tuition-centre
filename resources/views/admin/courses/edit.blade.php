@extends('layouts.app')

@section('title', 'Edit '.$course->code)

@section('content')
    @php
        $isAdmin = auth()->user()->hasRole('admin');
        $canManageDetails = auth()->user()->can('courses.manage_details');
        $canManageTeachers = auth()->user()->can('courses.manage_teachers');
        $canManageStudents = auth()->user()->can('courses.manage_students');
        $canManageSections = auth()->user()->can('sections.manage');

        // Tabs are separate page loads; the controller chose $activeTab and
        // loaded only that tab's data. Each tab partial still wraps itself
        // in x-show="tab === '...'", which is why `tab` is kept in x-data:
        // it is simply fixed for the life of the page now.
        $tabClasses = fn (string $tab) => $activeTab === $tab
            ? 'border-slate-900 text-slate-900'
            : 'border-transparent text-slate-700 hover:text-slate-900';
    @endphp

    <div class="mx-auto max-w-6xl space-y-8"
         x-data="{
             tab: '{{ $activeTab }}',
             openSection: (() => {
                 const v = new URLSearchParams(window.location.search).get('open');
                 return v ? parseInt(v) : null;
             })(),
             openMaterial: (() => {
                 const v = new URLSearchParams(window.location.search).get('open_material');
                 return v ? parseInt(v) : null;
             })(),
             openNewMaterialFor: null,
         }"
         x-init="$watch('openSection', value => {
             const url = new URL(window.location);
             if (value) url.searchParams.set('open', value);
             else url.searchParams.delete('open');
             history.replaceState(null, '', url);
         });
         $watch('openMaterial', value => {
             const url = new URL(window.location);
             if (value) url.searchParams.set('open_material', value);
             else url.searchParams.delete('open_material');
             history.replaceState(null, '', url);
         })">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $course->name }}</h1>
        </div>

        <div class="border-b border-slate-200">
            <nav class="-mb-px flex gap-6 text-sm">
                @if ($canManageDetails)
                    <a href="{{ route('courses.edit', [$course, 'tab' => 'details']) }}"
                       class="border-b-2 pb-2 {{ $tabClasses('details') }}">Details</a>
                @endif
                @if ($canManageTeachers)
                    <a href="{{ route('courses.edit', [$course, 'tab' => 'teachers']) }}"
                       class="border-b-2 pb-2 {{ $tabClasses('teachers') }}">Teachers ({{ $course->teachers_count }})</a>
                @endif
                @if ($canManageStudents)
                    <a href="{{ route('courses.edit', [$course, 'tab' => 'students']) }}"
                       class="border-b-2 pb-2 {{ $tabClasses('students') }}">Students ({{ $course->students_count }})</a>
                @endif
                @if ($canManageSections)
                    <a href="{{ route('courses.edit', [$course, 'tab' => 'materials']) }}"
                       class="border-b-2 pb-2 {{ $tabClasses('materials') }}">Materials ({{ $course->sections_count }})</a>
                @endif
            </nav>
        </div>

        {{-- One tab body per request. The controller already refused a tab
             the user may not see, so only the active one is rendered: the
             others are not hidden, they are absent. --}}
        @if ($activeTab === 'details')
            @include('admin.courses._tab-details')
        @elseif ($activeTab === 'teachers')
            @include('admin.courses._tab-teachers')
        @elseif ($activeTab === 'students')
            @include('admin.courses._tab-students')
        @elseif ($activeTab === 'materials')
            @include('admin.courses._tab-materials')
        @endif
    </div>
@endsection

@push('head')
    @include('admin.courses._styles')
@endpush

@push('scripts')
    @include('admin.courses._scripts')
@endpush
