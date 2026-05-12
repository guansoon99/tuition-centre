@extends('layouts.app')

@section('title', 'Edit user')

@section('content')
    <div class="mx-auto max-w-3xl space-y-4">
        <h1 class="text-xl font-semibold text-slate-900">Edit {{ $user->name }}</h1>
        @include('admin.users._form', [
            'user' => $user,
            'action' => route('users.update', $user),
            'method' => 'PATCH',
        ])
    </div>
@endsection
