@extends('layouts.auth')

@section('title', 'Login')

@section('content')
    <h2 class="mb-6 text-lg font-medium text-slate-900">Sign in to your account</h2>

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <label for="username" class="mb-1 block text-sm font-medium text-slate-700">
                Username
            </label>
            <input type="text" name="username" id="username"
                   value="{{ old('username') }}"
                   autocomplete="username" autofocus required
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
            @error('username')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="mb-1 block text-sm font-medium text-slate-700">
                Password
            </label>
            <input type="password" name="password" id="password"
                   autocomplete="current-password" required
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
            @error('password')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="remember" value="1" class="rounded border-slate-300">
            Remember me
        </label>

        <button type="submit"
                class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2">
            Sign in
        </button>
    </form>

    <p class="mt-6 text-center text-xs text-slate-500">
        Forgot your password?
        <a href="#" class="text-slate-700 underline" onclick="alert('Please contact your tuition centre administrator.'); return false;">
            Contact admin
        </a>
    </p>
@endsection
