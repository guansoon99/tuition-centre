        <section x-show="tab === 'students'" x-cloak class="space-y-4" x-data="{ importOpen: false }">
            {{-- Import result banner --}}
            @if (session('enrollment_import_result'))
                @php $r = session('enrollment_import_result'); @endphp
                @if (! empty($r['skipped']))
                    <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        <p class="font-medium">{{ count($r['skipped']) }} row(s) skipped:</p>
                        <ul class="mt-1 list-inside list-disc text-xs">
                            @foreach ($r['skipped'] as $s)
                                <li>Line {{ $s['line'] }} — <code>{{ $s['username'] ?: '(blank)' }}</code>: {{ $s['reason'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif

            <div class="flex justify-end">
                <button type="button" @click="importOpen = true"
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    + Import from Excel
                </button>
            </div>

            <form method="POST" action="{{ route('courses.enrollments.store', $course) }}"
                  x-data="{}"
                  class="space-y-3 rounded-md border border-slate-200 bg-white p-3">
                @csrf
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_170px_170px_auto]">
                    <select name="user_id" required data-search-select class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option value=""></option>
                        @foreach ($studentCandidates as $s)
                            <option value="{{ $s->id }}">{{ $s->username }} — {{ $s->name }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="enrolled_at" data-flatpickr required
                           value="{{ date('Y-m-d H:i') }}" x-ref="fromInput"
                           placeholder="From"
                           class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    <input type="text" name="expires_at" data-flatpickr
                           x-ref="endsInput" placeholder="Ends (optional)"
                           class="rounded-md border border-slate-300 px-3 py-1.5 text-sm placeholder:text-slate-600" />
                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-1.5 text-sm text-white hover:bg-slate-800">Enroll</button>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-sm text-slate-700">
                    <span>From → Ends:</span>
                    <button type="button" @click="$refs.endsInput._flatpickr.setDate(window.addMonths($refs.fromInput.value, 1), true)" class="rounded-md border border-slate-300 px-2 py-1 hover:bg-slate-50">+1 month</button>
                    <button type="button" @click="$refs.endsInput._flatpickr.setDate(window.addMonths($refs.fromInput.value, 3), true)" class="rounded-md border border-slate-300 px-2 py-1 hover:bg-slate-50">+3 months</button>
                    <button type="button" @click="$refs.endsInput._flatpickr.setDate(window.addMonths($refs.fromInput.value, 6), true)" class="rounded-md border border-slate-300 px-2 py-1 hover:bg-slate-50">+6 months</button>
                    <button type="button" @click="$refs.endsInput._flatpickr.setDate(window.addMonths($refs.fromInput.value, 12), true)" class="rounded-md border border-slate-300 px-2 py-1 hover:bg-slate-50">+1 year</button>
                    <button type="button" @click="$refs.endsInput._flatpickr.clear()" class="rounded-md border border-slate-300 px-2 py-1 hover:bg-slate-50">Forever</button>
                </div>
            </form>

            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="w-full min-w-[700px] text-sm [&_td]:whitespace-nowrap [&_th]:whitespace-nowrap">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-800">
                        <tr>
                            <th class="px-4 py-3">Username</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">From</th>
                            <th class="px-4 py-3">Ends</th>
                            <th class="px-4 py-3">Last accessed</th>
                            <th class="px-4 py-3">Active</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($enrollments as $e)
                            <tr>
                                <td class="px-4 py-3 font-mono text-sm text-slate-800">{{ $e->user->username }}</td>
                                <td class="px-4 py-3 text-slate-800">{{ $e->user->name }}</td>
                                <td class="px-4 py-3 font-mono text-sm text-slate-800">{{ $e->enrolled_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3 font-mono text-sm text-slate-800">{{ $e->expires_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="px-4 py-3 font-mono text-sm text-slate-800">{{ $e->last_accessed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($e->is_active)
                                        <span class="inline-flex min-w-[72px] items-center justify-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">
                                            <span class="mr-1 h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Active
                                        </span>
                                    @else
                                        <span class="inline-flex min-w-[72px] items-center justify-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                            <span class="mr-1 h-1.5 w-1.5 rounded-full bg-red-500"></span>Inactive
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        <form method="POST" action="{{ route('courses.enrollments.destroy', [$course, $e]) }}"
                                              onsubmit="return confirm('Unenroll {{ $e->user->username }}?');">
                                            @csrf @method('DELETE')
                                            <button type="submit"
                                                    class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-red-700">
                                                Unenroll
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-slate-400">No students enrolled.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Import modal (teleported to body so backdrop covers viewport) --}}
            <template x-teleport="body">
                <div x-show="importOpen" x-cloak
                     class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
                     @click.self="importOpen = false">
                    <form method="POST" action="{{ route('courses.enrollments.import', $course) }}"
                          enctype="multipart/form-data"
                          class="w-full max-w-md rounded-lg bg-white shadow-lg">
                        @csrf
                        <div class="border-b border-slate-200 px-5 py-3">
                            <h2 class="text-base font-semibold text-slate-900">Bulk Enroll</h2>
                        </div>

                        <div class="space-y-3 px-5 py-4 text-sm">
                            <div>
                                <div class="mb-1 flex items-center justify-between">
                                    <label class="block text-xs font-medium text-slate-700">File (.xlsx, .xls, .csv)</label>
                                    <a href="{{ route('courses.enrollments.import.template', $course) }}"
                                       class="inline-flex items-center rounded-md bg-sky-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-sky-700">
                                        Download Sample Excel
                                    </a>
                                </div>
                                <input type="file" name="file" required accept=".xlsx,.xls,.csv"
                                       class="block w-full text-sm text-slate-700 file:mr-3 file:rounded file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:text-white" />
                            </div>

                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-700">Expires on <span class="font-normal text-slate-500">(optional — leave blank for no expiry)</span></label>
                                <input type="text" name="expires_at" readonly placeholder="YYYY-MM-DD"
                                       x-init="flatpickr($el, { dateFormat: 'Y-m-d', disableMobile: true, allowInput: false })"
                                       class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 font-mono text-sm" />
                            </div>
                        </div>

                        <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                            <button type="button" @click="importOpen = false"
                                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800">
                                Import
                            </button>
                        </div>
                    </form>
                </div>
            </template>
        </section>
