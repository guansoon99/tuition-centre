@extends('layouts.app')

@section('title', 'Add Contact')

@section('content')
    <div class="mx-auto max-w-6xl space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Add Contact</h1>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5">
            @include('admin.contacts._form', [
                'action' => route('contacts.store'),
                'method' => 'POST',
            ])
        </div>
    </div>
@endsection
