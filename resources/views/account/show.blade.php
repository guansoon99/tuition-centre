@extends('layouts.app')

@section('title', 'Account')

@section('content')
    @php
        $user = auth()->user();
        $role = $user->roles->first()?->name;
        $canChangePassword = ! $user->hasRole('student')
            || \App\Models\SiteSettings::current()->students_can_change_password;
    @endphp

    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">
                {{ $user->name }}@if ($role) <span class="text-slate-400">|</span> <span class="text-slate-700">{{ ucfirst($role) }}</span>@endif
            </h1>
        </div>

        <section class="space-y-4 rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-base font-medium text-slate-900">Profile</h2>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Username</label>
                    <input type="text" value="{{ $user->username }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 font-mono text-sm text-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Name</label>
                    <input type="text" value="{{ $user->name }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Email</label>
                    <input type="text" value="{{ $user->email ?: '—' }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Phone</label>
                    <input type="text" value="{{ $user->phone ?: '—' }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Last login</label>
                    <input type="text" value="{{ $user->last_login_at?->format('Y-m-d H:i') ?? '—' }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 font-mono text-sm text-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Member since</label>
                    <input type="text" value="{{ $user->created_at->format('Y-m-d') }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 font-mono text-sm text-slate-900" />
                </div>
            </div>

        </section>

        @if ($canChangePassword)
        <section class="space-y-4 rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-base font-medium text-slate-900">Change password</h2>

            <form method="POST" action="{{ route('account.password') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="current_password" class="mb-1 block text-sm font-medium text-slate-700">Current password</label>
                    <input type="password" name="current_password" id="current_password" required autocomplete="current-password"
                           class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
                    @error('current_password')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="password" class="mb-1 block text-sm font-medium text-slate-700">New password</label>
                        <input type="password" name="password" id="password" required autocomplete="new-password"
                               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
                        @error('password')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="password_confirmation" class="mb-1 block text-sm font-medium text-slate-700">Confirm new password</label>
                        <input type="password" name="password_confirmation" id="password_confirmation" required autocomplete="new-password"
                               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
                    </div>
                </div>

                <button type="submit"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                    Update password
                </button>
            </form>
        </section>
        @endif
    </div>
@endsection
