@extends('layouts.app')

@section('title', 'Edit '.$course->code)

@section('content')
    @php
        $isAdmin = auth()->user()->hasRole('admin');
        $canManageDetails = auth()->user()->can('courses.manage_details');
        $canManageTeachers = auth()->user()->can('courses.manage_teachers');
        $canManageStudents = auth()->user()->can('courses.manage_students');
        $canManageSections = auth()->user()->can('sections.manage');

        $defaultTab = $canManageDetails ? 'details'
            : ($canManageTeachers ? 'teachers'
            : ($canManageStudents ? 'students'
            : ($canManageSections ? 'materials' : 'details')));
    @endphp

    <div class="mx-auto max-w-6xl space-y-8"
         x-data="{
             tab: new URLSearchParams(window.location.search).get('tab') || '{{ $defaultTab }}',
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
         x-init="$watch('tab', value => {
             const url = new URL(window.location);
             url.searchParams.set('tab', value);
             history.replaceState(null, '', url);
         });
         $watch('openSection', value => {
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
                    <button @click="tab = 'details'" :class="tab === 'details' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-700'"
                            class="border-b-2 pb-2">Details</button>
                @endif
                @if ($canManageTeachers)
                    <button @click="tab = 'teachers'" :class="tab === 'teachers' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-700'"
                            class="border-b-2 pb-2">Teachers ({{ $course->teachers->count() }})</button>
                @endif
                @if ($canManageStudents)
                    <button @click="tab = 'students'" :class="tab === 'students' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-700'"
                            class="border-b-2 pb-2">Students ({{ $course->students->count() }})</button>
                @endif
                @if ($canManageSections)
                    <button @click="tab = 'materials'" :class="tab === 'materials' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-700'"
                            class="border-b-2 pb-2">Materials ({{ $course->sections->count() }})</button>
                @endif
            </nav>
        </div>

        {{-- Tab bodies. Each is a partial in this directory; the permission
             guard stays here so this file reads as a table of contents. --}}
        @if ($canManageDetails)
            @include('admin.courses._tab-details')
        @endif

        @if ($canManageTeachers)
            @include('admin.courses._tab-teachers')
        @endif

        @if ($canManageStudents)
            @include('admin.courses._tab-students')
        @endif

        @if ($canManageSections)
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
