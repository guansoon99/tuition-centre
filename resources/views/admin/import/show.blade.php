@extends('layouts.app')

@section('title', 'Import students')

@section('content')
    <div class="mx-auto max-w-4xl space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Import students</h1>
            <p class="text-sm text-slate-500">Upload an Excel/CSV file. Preview first, then run the import.</p>
        </div>

        <details open class="rounded-lg border border-slate-200 bg-white p-4 text-sm">
            <summary class="cursor-pointer font-medium">Expected file format</summary>
            <p class="mt-2 text-slate-600">Header row required. Columns:</p>
            <ul class="mt-2 list-disc pl-6 text-slate-600">
                <li><strong>name</strong> (required)</li>
                <li>phone (optional)</li>
                <li>ic_number (optional)</li>
                <li>candidate_number (optional)</li>
                <li>course_code (optional — must match an existing course if given; leave blank to create the user without enrolling)</li>
                <li>expires_at (optional, YYYY-MM-DD)</li>
            </ul>
            <a href="{{ route('import.sample') }}"
               class="mt-3 inline-flex items-center rounded-md bg-sky-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-sky-700">
                Download sample Excel
            </a>
        </details>

        <form method="POST" action="{{ route('import.preview') }}" enctype="multipart/form-data"
              class="space-y-3 rounded-lg border border-slate-200 bg-white p-4">
            @csrf
            <label class="block text-sm font-medium text-slate-700">Excel / CSV file</label>
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                   class="block w-full text-sm text-slate-700 file:mr-3 file:rounded file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:text-white" />
            @error('file') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm hover:bg-slate-100">
                    Preview
                </button>
                <button type="submit" formaction="{{ route('import.run') }}"
                        onclick="return confirm('Run the import now? Users and enrollments will be created.');"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    Import &amp; generate credentials
                </button>
                @if ($credentialsFile)
                    <a href="{{ route('import.credentials') }}"
                       class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        Download last credentials
                    </a>
                @endif
            </div>
        </form>

        @if ($preview)
            @include('admin.import._results', ['result' => $preview, 'title' => 'Preview (no changes saved)'])
        @endif

        @if ($result)
            @include('admin.import._results', ['result' => $result, 'title' => 'Import result'])
        @endif
    </div>
@endsection

@if ($result && $credentialsFile && ! empty($result['ok']))
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
