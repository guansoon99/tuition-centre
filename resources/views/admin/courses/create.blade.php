@extends('layouts.app')

@section('title', 'New course')

@section('content')
    <div class="mx-auto max-w-3xl space-y-4">
        <h1 class="text-xl font-semibold text-slate-900">New course</h1>
        @include('admin.courses._form', ['action' => route('courses.store')])
    </div>
@endsection
