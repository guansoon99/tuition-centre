@extends('layouts.app')

@section('title', 'Edit section')

@section('content')
    <div class="mx-auto max-w-2xl space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Edit section</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $section->course->name }} · {{ $section->course->code }}</p>
        </div>

        @include('teacher.sections._form', [
            'section' => $section,
            'action' => route('sections.update', $section),
            'method' => 'PATCH',
        ])

        <form method="POST" action="{{ route('sections.destroy', $section) }}"
              onsubmit="return confirm('Delete this section and all its materials?');"
              class="border-t border-slate-200 pt-4">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-sm text-red-600 hover:underline">Delete section</button>
        </form>
    </div>
@endsection
