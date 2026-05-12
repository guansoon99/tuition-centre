@extends('layouts.app')

@section('title', 'Edit material')

@section('content')
    <div class="mx-auto max-w-2xl space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Edit material</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $material->section->course->name }} · {{ $material->section->title }}</p>
        </div>

        @include('teacher.materials._form', [
            'material' => $material,
            'action' => route('materials.update', $material),
            'method' => 'PATCH',
        ])

        <form method="POST" action="{{ route('materials.destroy', $material) }}"
              onsubmit="return confirm('Delete this material?');"
              class="border-t border-slate-200 pt-4">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-sm text-red-600 hover:underline">Delete material</button>
        </form>
    </div>
@endsection
