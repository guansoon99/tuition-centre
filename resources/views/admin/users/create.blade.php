@extends('layouts.app')

@section('title', 'New User')

@section('content')
    <div class="mx-auto max-w-3xl space-y-4">
        <h1 class="text-xl font-semibold text-slate-900">New User</h1>
        @include('admin.users._form', ['action' => route('users.store')])
    </div>
@endsection
