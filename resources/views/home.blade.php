@extends('layouts.app')

@section('title', 'Home')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <section class="rounded-lg border border-slate-200 bg-white p-6">
            <h1 class="text-xl font-semibold text-slate-900">
                Hello, {{ auth()->user()->name }}.
            </h1>
            <p class="mt-2 text-sm text-slate-600">
                You're logged in as <span class="font-mono">{{ auth()->user()->username }}</span>
                ({{ auth()->user()->roles->pluck('name')->join(', ') ?: 'no role' }}).
            </p>
            <p class="mt-2 text-xs text-slate-400">
                The full student homepage (banner, recently accessed, enrolled courses) lands in step 5.
            </p>
        </section>

        @auth
            @php $user = auth()->user(); @endphp
            <section class="rounded-lg border border-slate-200 bg-white p-6">
                <h2 class="mb-3 text-base font-medium text-slate-900">Quick stats</h2>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-slate-500">Enrolled courses</dt>
                        <dd class="text-2xl font-semibold">{{ $user->enrolledCourses()->count() }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Taught courses</dt>
                        <dd class="text-2xl font-semibold">{{ $user->taughtCourses()->count() }}</dd>
                    </div>
                </dl>
            </section>
        @endauth
    </div>
@endsection
