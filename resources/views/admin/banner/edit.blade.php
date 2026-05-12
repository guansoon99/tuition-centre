@extends('layouts.app')

@section('title', 'Edit slide')

@section('content')
    <div class="mx-auto max-w-2xl space-y-4">
        <h1 class="text-xl font-semibold text-slate-900">Edit banner slide</h1>
        @include('admin.banner._form', [
            'slide' => $slide,
            'action' => route('banner.update', $slide),
            'method' => 'PATCH',
        ])
    </div>
@endsection
