@extends('layouts.app')

@section('title', $material->title)

@include('partials.prose-page-styles')

@section('content')
    <div class="mx-auto max-w-3xl space-y-4">
        <h1 class="text-2xl font-semibold text-slate-900">{{ $material->title }}</h1>

        <article class="prose-page rounded-lg border border-slate-200 bg-white p-6 text-slate-800 shadow-sm">
            {!! $material->body !!}
        </article>
    </div>
@endsection
