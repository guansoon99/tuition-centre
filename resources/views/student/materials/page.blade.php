@extends('layouts.app')

@section('title', $material->title)

@push('head')
    <style>
        .prose-page h1 { font-size: 1.75rem; font-weight: 700; margin: 1rem 0 0.75rem; color: rgb(15 23 42); }
        .prose-page h2 { font-size: 1.375rem; font-weight: 600; margin: 1rem 0 0.5rem; color: rgb(15 23 42); }
        .prose-page h3 { font-size: 1.125rem; font-weight: 600; margin: 0.75rem 0 0.5rem; color: rgb(15 23 42); }
        .prose-page p  { margin: 0.75rem 0; line-height: 1.65; }
        .prose-page a  { color: rgb(2 132 199); text-decoration: underline; }
        .prose-page ul { list-style: disc; padding-left: 1.5rem; margin: 0.75rem 0; }
        .prose-page ol { list-style: decimal; padding-left: 1.5rem; margin: 0.75rem 0; }
        .prose-page blockquote { border-left: 3px solid rgb(203 213 225); padding-left: 0.75rem; color: rgb(71 85 105); margin: 0.75rem 0; }
        .prose-page img { max-width: 100%; height: auto; border-radius: 0.375rem; margin: 0.75rem 0; }
        .prose-page video { max-width: 100%; height: auto; border-radius: 0.375rem; margin: 0.75rem 0; display: block; }
        .prose-page pre, .prose-page code { background: rgb(241 245 249); padding: 0.1rem 0.3rem; border-radius: 0.25rem; font-family: monospace; }
        .prose-page pre { padding: 0.75rem; overflow-x: auto; }
        .prose-page hr { border-top: 1px solid rgb(203 213 225); margin: 1rem 0; }
        .prose-page .ql-align-center  { text-align: center; }
        .prose-page .ql-align-right   { text-align: right; }
        .prose-page .ql-align-justify { text-align: justify; }
        .prose-page .ql-align-center img { display: block; margin-left: auto; margin-right: auto; }
        .prose-page .ql-align-right  img { display: block; margin-left: auto; margin-right: 0; }
        .prose-page .ql-align-justify img{ display: block; margin-left: auto; margin-right: auto; }
        .prose-page table { border-collapse: collapse; margin: 1rem 0; width: 100%; color: #000; }
        .prose-page th, .prose-page td { border: 1px solid #000; padding: 0.375rem 0.5rem; text-align: left; vertical-align: top; color: #000; }
        .prose-page th { background: rgb(241 245 249); font-weight: 600; }
    </style>
@endpush

@section('content')
    <div class="mx-auto max-w-3xl space-y-4">
        <h1 class="text-2xl font-semibold text-slate-900">{{ $material->title }}</h1>

        <article class="prose-page rounded-lg border border-slate-200 bg-white p-6 text-slate-800 shadow-sm">
            {!! $material->body !!}
        </article>
    </div>
@endsection
