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

        {{-- The eye reveals what was typed. Generated student passwords are
             long and handed out on paper, so a typo is the usual reason a
             login fails; seeing the field beats retyping it blind. --}}
        <div x-data="{ show: false }">
            <label for="password" class="mb-1 block text-sm font-medium text-slate-700">
                Password
            </label>
            <div class="relative">
                <input type="password" :type="show ? 'text' : 'password'" name="password" id="password"
                       autocomplete="current-password" required
                       class="w-full rounded-md border border-slate-300 py-2 pl-3 pr-10 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
                <button type="button" @click="show = ! show"
                        :aria-label="show ? 'Hide password' : 'Show password'"
                        :title="show ? 'Hide password' : 'Show password'"
                        :aria-pressed="show"
                        class="absolute inset-y-0 right-0 flex items-center px-3 text-slate-600 hover:text-slate-900">
                    {{-- eye --}}
                    <svg x-show="! show" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    {{-- eye, struck through --}}
                    <svg x-show="show" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                    </svg>
                </button>
            </div>
            @error('password')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

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
