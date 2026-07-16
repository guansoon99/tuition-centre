@extends('layouts.app')

@section('title', 'Demo placeholder')

@section('content')
    <div class="mx-auto max-w-2xl rounded-lg border border-amber-200 bg-amber-50 p-6">
        <h1 class="text-base font-semibold text-amber-900">Demo placeholder</h1>
        <p class="mt-2 text-sm text-amber-800">
            <strong>{{ $material->title }}</strong> would download from Cloudflare R2 in production.
            Local dev seeded a fake file path, so there's nothing to stream.
        </p>
        <p class="mt-2 text-xs text-amber-700">
            file_path: <span class="font-mono">{{ $material->file_path }}</span>
        </p>
    </div>
@endsection
