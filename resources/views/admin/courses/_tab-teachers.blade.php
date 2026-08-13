        <section x-show="tab === 'teachers'" x-cloak class="space-y-4">
            <form method="POST" action="{{ route('courses.teachers.store', $course) }}"
                  x-data="{}"
                  class="space-y-3 rounded-md border border-slate-200 bg-white p-3">
                @csrf
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_170px_170px_auto]">
                    <select name="user_id" required data-search-select class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option value=""></option>
                        @foreach ($teacherCandidates as $t)
                            <option value="{{ $t->id }}">{{ $t->name }} ({{ $t->username }})</option>
                        @endforeach
                    </select>
                    <input type="text" name="assigned_at" data-flatpickr required
                           value="{{ date('Y-m-d H:i') }}" x-ref="fromInput"
                           placeholder="From"
                           class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    <input type="text" name="ends_at" data-flatpickr
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
                        @forelse ($course->teachers as $t)
                            @php
                                // "Active" for a teacher = no end date OR end date is in the future.
                                // Since course_teacher merged into enrollments, the pivot columns
                                // are enrolled_at (was assigned_at) and expires_at (was ends_at).
                                $tEnds = $t->pivot->expires_at ? \Carbon\Carbon::parse($t->pivot->expires_at) : null;
                                $tActive = $tEnds === null || $tEnds->isFuture();
                            @endphp
                            <tr>
                                <td class="px-4 py-3 font-mono text-sm text-slate-800">{{ $t->username }}</td>
                                <td class="px-4 py-3 text-slate-800">{{ $t->name }}</td>
                                <td class="px-4 py-3 font-mono text-sm text-slate-800">{{ $t->pivot->enrolled_at ? \Carbon\Carbon::parse($t->pivot->enrolled_at)->format('Y-m-d H:i') : '—'}}</td>
                                <td class="px-4 py-3 font-mono text-sm text-slate-800">{{ $tEnds?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="px-4 py-3 font-mono text-sm text-slate-800">{{ $t->pivot->last_accessed_at ? \Carbon\Carbon::parse($t->pivot->last_accessed_at)->format('Y-m-d H:i') : '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($tActive)
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
                                        <form method="POST" action="{{ route('courses.teachers.destroy', [$course, $t]) }}"
                                              onsubmit="return confirm('Unenroll {{ $t->name }}?');">
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
                            <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-slate-400">No teachers assigned.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
