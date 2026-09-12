@extends('layouts.app')

@section('title', 'Import Students')

@section('content')
    <div class="mx-auto max-w-6xl space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Import Students</h1>
        </div>

        @if ($active)
            {{-- A run in flight. The job reports progress through the cache; this
                 polls it and moves on to the result page when the job is done. --}}
            <div x-data="importProgress(@js(route('import.status', $active)), @js(route('import.show', ['import' => $active->id])))"
                 x-init="start()"
                 class="space-y-3 rounded-lg border border-slate-200 bg-white p-4 text-sm">
                <p class="font-medium text-slate-900">
                    Importing <span class="font-mono">{{ $active->original_name }}</span>…
                </p>
                <div class="h-3 w-full overflow-hidden rounded-full bg-slate-200">
                    <div class="h-3 rounded-full bg-emerald-500 transition-all duration-500" :style="'width:' + percent + '%'"></div>
                </div>
                <p class="text-slate-700"
                   x-text="status === 'queued'
                       ? 'Waiting for the import worker to pick this up…'
                       : done + ' of ' + total + ' rows · ' + percent + '%'"></p>
                <p x-show="stale" x-cloak class="text-amber-800">
                    Still waiting after a minute. The import worker may not be running; ask whoever looks after the server.
                </p>
                <p class="text-xs text-slate-600">
                    You can leave this page; the import carries on. Come back here to see the result.
                </p>
            </div>
        @else
            <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm">
                <p class="font-medium">Expected file format</p>
                <ul class="mt-2 max-w-[14rem] list-disc space-y-0.5 pl-6">
                    <li><span class="flex justify-between gap-4"><span>Name</span><span>(required)</span></span></li>
                    <li><span class="flex justify-between gap-4"><span>Phone</span><span>(optional)</span></span></li>
                    <li><span class="flex justify-between gap-4"><span>Email</span><span>(optional)</span></span></li>
                    <li><span class="flex justify-between gap-4"><span>IC Number</span><span>(optional)</span></span></li>
                    <li><span class="flex justify-between gap-4"><span>Candidate Number</span><span>(optional)</span></span></li>
                    <li><span class="flex justify-between gap-4"><span>Course Code</span><span>(optional)</span></span></li>
                    <li><span class="flex justify-between gap-4"><span>Expires At</span><span>(optional)</span></span></li>
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
                @error('credentials') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

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
                            Import &amp; Generate Credentials
                        </button>
                    @elseif (! $hasPreview)
                        <button type="submit" disabled
                                class="rounded-md bg-slate-300 px-4 py-2 text-sm font-medium text-white cursor-not-allowed">
                            Import &amp; Generate Credentials
                        </button>
                    @endif

                    @if ($lastCredentials)
                        <a href="{{ route('import.credentials', $lastCredentials) }}"
                           class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                            Download Last Credentials
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

            @if ($import)
                @if ($import->status === \App\Models\StudentImport::STATUS_FAILED)
                    <div class="rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-900">
                        <p class="font-semibold">The import of {{ $import->original_name }} failed. Nothing was created.</p>
                        <p class="mt-1 font-mono text-xs">{{ $import->error }}</p>
                        <p class="mt-2">Fix the cause and run the file again from the top of this page.</p>
                    </div>
                @else
                    @include('admin.import._results', ['result' => $import->result ?? [], 'title' => 'Import result'])

                    @if ($import->credentials_path)
                        <a href="{{ route('import.credentials', $import) }}"
                           class="inline-flex rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                            Download Credentials
                        </a>
                    @endif
                @endif
            @endif
        @endif
    </div>
@endsection

@if ($active)
    @push('scripts')
        <script>
            // The progress panel above. Polls the status endpoint until the
            // job reports done or failed, then loads the result page.
            window.importProgress = function (statusUrl, doneUrl) {
                return {
                    status: 'queued',
                    done: 0,
                    total: 0,
                    percent: 0,
                    stale: false,
                    startedAt: Date.now(),
                    timer: null,

                    start() {
                        this.poll();
                        this.timer = setInterval(() => this.poll(), 1500);
                    },

                    async poll() {
                        try {
                            const res = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
                            if (! res.ok) return;
                            const p = await res.json();
                            this.status = p.status;
                            this.done = p.done;
                            this.total = p.total;
                            this.percent = p.percent;
                            this.stale = p.status === 'queued' && (Date.now() - this.startedAt) > 60000;
                            if (p.status === 'done' || p.status === 'failed') {
                                clearInterval(this.timer);
                                window.location = doneUrl;
                            }
                        } catch (e) {
                            // Transient; the next tick tries again.
                        }
                    },
                };
            };
        </script>
    @endpush
@endif

@if ($import && $import->credentials_path)
    @push('scripts')
        <script>
            // Start the credentials download on arrival, as the inline import did.
            document.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => {
                    const a = document.createElement('a');
                    a.href = @js(route('import.credentials', $import));
                    a.style.display = 'none';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                }, 500);
            });
        </script>
    @endpush
@endif
