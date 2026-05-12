@extends('layouts.app')

@section('title', 'Upload banner')

@section('content')
    <div class="mx-auto max-w-2xl space-y-4">
        <h1 class="text-xl font-semibold text-slate-900">Upload banner</h1>
        @include('admin.banner._form', ['action' => route('banner.store')])
    </div>
@endsection
