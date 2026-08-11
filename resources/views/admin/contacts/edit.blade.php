@extends('layouts.app')

@section('title', 'Edit Contact')

@section('content')
    <div class="mx-auto max-w-6xl space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Edit Contact</h1>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5">
            @include('admin.contacts._form', [
                'contact' => $contact,
                'action' => route('contacts.update', $contact),
                'method' => 'PATCH',
            ])
        </div>
    </div>
@endsection
