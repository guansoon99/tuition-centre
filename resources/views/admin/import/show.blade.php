@extends('layouts.app')

@section('title', 'Import Students')

@section('content')
    <div class="mx-auto max-w-4xl space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Import Students</h1>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm">
            <p class="font-medium">Expected file format</p>
            <ul class="mt-2 max-w-[14rem] list-disc space-y-0.5 pl-6 text-slate-600">
                <li><span class="flex justify-between gap-4"><span>Name</span><span class="text-slate-500">(required)</span></span></li>
                <li><span class="flex justify-between gap-4"><span>Phone</span><span class="text-slate-500">(optional)</span></span></li>
                <li><span class="flex justify-between gap-4"><span>IC Number</span><span class="text-slate-500">(optional)</span></span></li>
                <li><span class="flex justify-between gap-4"><span>Candidate Number</span><span class="text-slate-500">(optional)</span></span></li>
                <li><span class="flex justify-between gap-4"><span>Course Code</span><span class="text-slate-500">(optional)</span></span></li>
                <li><span class="flex justify-between gap-4"><span>Expires At</span><span class="text-slate-500">(optional)</span></span></li>
            </ul>
            <a href="{{ route('import.sample') }}"
               class="mt-3 inline-flex items-center rounded-md bg-sky-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-sky-700">
                Download Sample Excel
            </a>
        </div>

        @php $hasPreview = (bool) $preview; @endphp

        <form method="POST" action="{{ route('import.preview') }}" enctype="multipart/form-data"
              x-data class="space-y-3 rounded-lg border border-slate-200 bg-white p-4">
            @csrf
            <label class="block text-sm font-medium text-slate-700">Excel / CSV file</label>
            <input type="file" name="file" accept=".xlsx,.xls,.csv"
                   @if (! $hasPreview) required @endif
                   @change="$el.closest('form').submit()"
                   class="block w-full text-sm text-slate-700 file:mr-3 file:rounded file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:text-white" />
            @error('file') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

            @if ($hasPreview && $stashedFileName)
                <p class="text-sm text-slate-700">
                    Ready to import: <span class="font-mono text-slate-900">{{ $stashedFileName }}</span>.
                </p>
            @endif

            @php $dupCount = $hasPreview ? count($preview['skipped'] ?? []) : 0; @endphp

            <div class="flex flex-wrap gap-3">
                {{-- Only show the plain Import button when there are no duplicates.
                     When duplicates exist, the alert below takes over with 3 explicit actions. --}}
                @if ($hasPreview && $dupCount === 0)
                    <button type="submit" formaction="{{ route('import.run') }}"
                            onclick="return confirm('Run the import now? Users and enrollments will be created.');"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                        Import &amp; generate credentials
                    </button>
                @elseif (! $hasPreview)
                    <button type="submit" disabled
                            class="rounded-md bg-slate-300 px-4 py-2 text-sm font-medium text-white cursor-not-allowed">
                        Import &amp; generate credentials
                    </button>
                @endif

                @if ($credentialsFile)
                    <a href="{{ route('import.credentials') }}"
                       class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        Download last credentials
                    </a>
                @endif
            </div>
        </form>

        @if ($preview)
            @include('admin.import._results', ['result' => $preview, 'title' => 'Preview'])

            @if ($dupCount > 0)
                <div class="space-y-3 rounded-md border border-amber-300 bg-amber-50 p-4 text-amber-900">
                    <div class="flex items-start gap-3">
                        <svg class="mt-0.5 h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <div>
                            <p class="font-semibold">
                                {{ $dupCount }} duplicate {{ \Illuminate\Support\Str::plural('name', $dupCount) }} found in this file.
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2 pt-1">
                        {{-- Green = recommended safe action --}}
                        <form method="POST" action="{{ route('import.run') }}" class="inline"
                              onsubmit="return confirm('Skip the {{ $dupCount }} duplicate {{ \Illuminate\Support\Str::plural('name', $dupCount) }} and import the rest?');">
                            @csrf
                            <input type="hidden" name="mode" value="skip">
                            <button type="submit"
                                    class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700">
                                Skip Duplicates &amp; Import
                            </button>
                        </form>

                        {{-- Amber = warning, unusual choice --}}
                        <form method="POST" action="{{ route('import.run') }}" class="inline"
                              onsubmit="return confirm('Create everyone, including {{ $dupCount }} duplicate {{ \Illuminate\Support\Str::plural('name', $dupCount) }}?');">
                            @csrf
                            <input type="hidden" name="mode" value="all">
                            <button type="submit"
                                    class="rounded-md bg-amber-500 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-600">
                                Create Everyone
                            </button>
                        </form>

                        {{-- Red = walk away / cancel --}}
                        <form method="POST" action="{{ route('import.cancel') }}" class="inline">
                            @csrf
                            <button type="submit"
                                    class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
                                Cancel
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        @endif

        @if ($result)
            @include('admin.import._results', ['result' => $result, 'title' => 'Import result'])
        @endif
    </div>
@endsection

@if ($result && $credentialsFile && (! empty($result['ok']) || ! empty($result['skipped'])))
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => {
                    const a = document.createElement('a');
                    a.href = '{{ route('import.credentials') }}';
                    a.style.display = 'none';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                }, 800);
            });
        </script>
    @endpush
@endif
