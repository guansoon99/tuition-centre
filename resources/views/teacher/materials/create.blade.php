@extends('layouts.app')

@section('title', 'New material')

@section('content')
    <div class="mx-auto max-w-2xl">
        <h1 class="text-xl font-semibold text-slate-900">Add material</h1>
        <p class="mb-6 mt-1 text-sm text-slate-500">{{ $section->course->name }} · {{ $section->title }}</p>

        @include('teacher.materials._form', ['action' => route('materials.store', $section)])
    </div>
@endsection
