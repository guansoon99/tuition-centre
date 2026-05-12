@extends('layouts.app')

@section('title', 'New section')

@section('content')
    <div class="mx-auto max-w-2xl">
        <h1 class="text-xl font-semibold text-slate-900">Add section to {{ $course->name }}</h1>
        <p class="mb-6 mt-1 text-sm text-slate-500">{{ $course->code }}</p>

        @include('teacher.sections._form', ['action' => route('sections.store', $course)])
    </div>
@endsection
