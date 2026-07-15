@extends('layouts.app')

@section('title', 'Edit '.$course->code)

@section('content')
    @php
        $isAdmin = auth()->user()->hasRole('admin');
        $canManageTeachers = auth()->user()->can('courses.manage_teachers');
        $canManageStudents = auth()->user()->can('courses.manage_students');
        $canManageSections = auth()->user()->can('sections.manage');

        $defaultTab = $isAdmin ? 'details'
            : ($canManageTeachers ? 'teachers'
            : ($canManageStudents ? 'students'
            : ($canManageSections ? 'sections' : 'details')));
    @endphp

    <div class="mx-auto max-w-4xl space-y-8"
         x-data="{
             tab: new URLSearchParams(window.location.search).get('tab') || '{{ $defaultTab }}',
             openSection: (() => {
                 const v = new URLSearchParams(window.location.search).get('open');
                 return v ? parseInt(v) : null;
             })(),
             openMaterial: (() => {
                 const v = new URLSearchParams(window.location.search).get('open_material');
                 return v ? parseInt(v) : null;
             })(),
         }"
         x-init="$watch('tab', value => {
             const url = new URL(window.location);
             url.searchParams.set('tab', value);
             history.replaceState(null, '', url);
         });
         $watch('openSection', value => {
             const url = new URL(window.location);
             if (value) url.searchParams.set('open', value);
             else url.searchParams.delete('open');
             history.replaceState(null, '', url);
         });
         $watch('openMaterial', value => {
             const url = new URL(window.location);
             if (value) url.searchParams.set('open_material', value);
             else url.searchParams.delete('open_material');
             history.replaceState(null, '', url);
         })">
        <div>
            <a href="{{ route('courses.index') }}" class="text-xs text-slate-500 hover:underline">&larr; All courses</a>
            <div class="mt-2 flex items-baseline gap-3">
                <span class="font-mono text-xs text-slate-500">{{ $course->code }}</span>
                <h1 class="text-2xl font-semibold text-slate-900">{{ $course->name }}</h1>
            </div>
            @if ($course->description)
                <p class="mt-1 text-sm text-slate-600">{{ $course->description }}</p>
            @endif
        </div>

        <div class="border-b border-slate-200">
            <nav class="-mb-px flex gap-6 text-sm">
                @if ($isAdmin)
                    <button @click="tab = 'details'" :class="tab === 'details' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-700'"
                            class="border-b-2 pb-2">Details</button>
                @endif
                @if ($canManageTeachers)
                    <button @click="tab = 'teachers'" :class="tab === 'teachers' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-700'"
                            class="border-b-2 pb-2">Teachers ({{ $course->teachers->count() }})</button>
                @endif
                @if ($canManageStudents)
                    <button @click="tab = 'students'" :class="tab === 'students' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-700'"
                            class="border-b-2 pb-2">Students ({{ $course->students->count() }})</button>
                @endif
                @if ($canManageSections)
                    <button @click="tab = 'sections'" :class="tab === 'sections' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-700'"
                            class="border-b-2 pb-2">Sections ({{ $course->sections->count() }})</button>
                @endif
            </nav>
        </div>

        @if ($isAdmin)
        <section x-show="tab === 'details'" x-cloak>
            @include('admin.courses._form', [
                'course' => $course,
                'action' => route('courses.update', $course),
                'method' => 'PATCH',
            ])
        </section>
        @endif

        @if ($canManageTeachers)
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
                           class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-1.5 text-sm text-white hover:bg-slate-800">Assign</button>
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
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($course->teachers as $t)
                            <tr>
                                <td class="px-4 py-3 font-mono text-sm text-slate-800">{{ $t->username }}</td>
                                <td class="px-4 py-3 text-slate-800">{{ $t->name }}</td>
                                <td class="px-4 py-3 font-mono text-sm text-slate-800">{{ $t->pivot->assigned_at ? \Carbon\Carbon::parse($t->pivot->assigned_at)->format('Y-m-d H:i') : '—'}}</td>
                                <td class="px-4 py-3 font-mono text-sm text-slate-800">{{ $t->pivot->ends_at ? \Carbon\Carbon::parse($t->pivot->ends_at)->format('Y-m-d H:i') : '—'}}</td>
                                <td class="px-4 py-3 font-mono text-sm text-slate-800">{{ $t->pivot->last_accessed_at ? \Carbon\Carbon::parse($t->pivot->last_accessed_at)->format('Y-m-d H:i') : '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        <form method="POST" action="{{ route('courses.teachers.destroy', [$course, $t]) }}"
                                              onsubmit="return confirm('Remove {{ $t->name }}?');">
                                            @csrf @method('DELETE')
                                            <button type="submit"
                                                    class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-red-700">
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-slate-400">No teachers assigned.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @endif

        @if ($canManageStudents)
        <section x-show="tab === 'students'" x-cloak class="space-y-4">
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
                           class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
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
                        @forelse ($course->enrollments()->with('user')->orderByDesc('enrolled_at')->get() as $e)
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
        </section>
        @endif

        @if ($canManageSections)
        <section x-show="tab === 'sections'" x-cloak class="space-y-4">
            <div class="flex items-center justify-between">
                <p class="text-sm text-slate-500">{{ $course->sections->count() }} section{{ $course->sections->count() === 1 ? '' : 's' }}</p>
            </div>

            @if ($course->sections->isEmpty())
                {{-- Empty state: single "+ Add first section" button --}}
                <form method="POST" action="{{ route('sections.quick-insert', $course) }}">
                    @csrf
                    <input type="hidden" name="position" value="first">
                    <button type="submit"
                            class="w-full rounded-md border border-dashed border-slate-300 bg-white py-6 text-sm font-medium text-slate-500 hover:border-slate-400 hover:bg-slate-50 hover:text-slate-700">
                        + Add first section
                    </button>
                </form>
            @else
                <div class="space-y-2">
                    {{-- + button at the very top (insert as first) --}}
                    <form method="POST" action="{{ route('sections.quick-insert', $course) }}">
                        @csrf
                        <input type="hidden" name="position" value="first">
                        <button type="submit"
                                class="group flex w-full items-center justify-center rounded-md border border-dashed border-slate-300 py-2 text-xs font-medium text-slate-500 transition hover:border-slate-400 hover:bg-slate-50 hover:text-slate-700">
                            <span class="group-hover:opacity-100">+ Insert section here</span>
                        </button>
                    </form>

                    @foreach ($course->sections as $section)
                        <article class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                            <header class="border-b border-slate-100 bg-slate-50 px-4 py-3">
                                <div class="flex items-baseline justify-between gap-2">
                                    <h2 class="text-base font-medium text-slate-900">
                                        {{ $section->title }}
                                        @if ($section->scheduled_at && $section->scheduled_at->isFuture())
                                            <span class="ml-1 rounded bg-sky-100 px-1.5 font-mono text-xs text-sky-700"
                                                  title="Goes live at {{ $section->scheduled_at->format('Y-m-d H:i') }}">
                                                scheduled
                                            </span>
                                        @elseif (! $section->is_published && ! $section->scheduled_at)
                                            <span class="ml-1 rounded bg-amber-100 px-1.5 text-xs text-amber-800">draft</span>
                                        @endif
                                    </h2>
                                    <div class="flex items-center gap-2">
                                        <button type="button"
                                                @click="openSection = {{ $section->id }}"
                                                class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-emerald-700">
                                            Edit
                                        </button>
                                    </div>
                                </div>
                            </header>

                            @if ($section->materials->isEmpty())
                                <p class="px-4 py-4 text-xs italic text-slate-400">No resources yet — click "+ Add resource".</p>
                            @else
                                <div class="divide-y divide-slate-100">
                                    @foreach ($section->materials as $material)
                                        <div class="flex items-center gap-2 pr-3">
                                            <div class="flex-1"><x-material-item :material="$material" /></div>
                                            <button type="button"
                                                    @click="openMaterial = {{ $material->id }}"
                                                    title="Edit material"
                                                    class="inline-flex items-center justify-center rounded-md bg-slate-100 p-1.5 text-slate-700 hover:bg-slate-200 hover:text-slate-900">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                          d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                                </svg>
                                            </button>
                                        </div>

                                        {{-- Edit modal for this material --}}
                                        <div x-show="openMaterial === {{ $material->id }}" x-cloak
                                             class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto p-4">
                                            <div @click="openMaterial = null"
                                                 x-show="openMaterial === {{ $material->id }}" x-cloak
                                                 class="fixed inset-0 bg-black/40"></div>
                                            <div x-show="openMaterial === {{ $material->id }}" x-cloak
                                                 class="relative mt-12 w-full max-w-xl rounded-lg bg-white p-6 shadow-xl">
                                                <div class="mb-4 flex items-center justify-between">
                                                    <h3 class="text-lg font-semibold text-slate-900">Edit material</h3>
                                                    <button type="button" @click="openMaterial = null"
                                                            class="text-slate-400 hover:text-slate-600">
                                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                        </svg>
                                                    </button>
                                                </div>

                                                <form method="POST" action="{{ route('materials.update', $material) }}" enctype="multipart/form-data"
                                                      x-data="{ matType: '{{ $material->type }}' }"
                                                      x-init="
                                                          const tryInit = () => initQuillEditor($refs.matQuillContainer_{{ $material->id }}, $refs.matQuillInput_{{ $material->id }});
                                                          if (matType === 'text') $nextTick(tryInit);
                                                          $watch('matType', v => { if (v === 'text') $nextTick(tryInit); });
                                                      "
                                                      class="space-y-4">
                                                    @csrf @method('PATCH')

                                                    <div>
                                                        <label class="mb-1 block text-sm font-medium text-slate-700">Title</label>
                                                        <input type="text" name="title" required value="{{ $material->title }}"
                                                               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
                                                    </div>

                                                    <div>
                                                        <label class="mb-1 block text-sm font-medium text-slate-700">Type</label>
                                                        <select name="type" x-model="matType"
                                                                class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                                                            <option value="pdf">PDF (uploaded)</option>
                                                            <option value="external_link">External link</option>
                                                            <option value="video_link">Video link (Google Drive)</option>
                                                            <option value="text">Text block</option>
                                                            <option value="countdown">Countdown timer</option>
                                                        </select>
                                                    </div>

                                                    <div x-show="matType === 'pdf'" x-data="{ chosen: null }" x-cloak>
                                                        <label class="mb-1 block text-sm font-medium text-slate-700">PDF file</label>
                                                        <input type="file" name="file" accept="application/pdf"
                                                               @change="chosen = $event.target.files[0] || null"
                                                               class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white" />
                                                        <template x-if="chosen">
                                                            <p class="mt-1 text-xs text-slate-500">
                                                                Selected: <span x-text="chosen.name" class="font-mono"></span>
                                                            </p>
                                                        </template>
                                                        @if ($material->file_path)
                                                            <p class="mt-1 text-xs text-slate-500" x-show="!chosen">
                                                                Current: {{ basename($material->file_path) }} — leave empty to keep.
                                                            </p>
                                                        @endif
                                                    </div>

                                                    <div x-show="matType === 'external_link' || matType === 'video_link'" x-cloak>
                                                        <label class="mb-1 block text-sm font-medium text-slate-700">URL</label>
                                                        <input type="url" name="external_url" value="{{ $material->external_url }}"
                                                               placeholder="https://..."
                                                               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                                                    </div>

                                                    <div x-show="matType === 'text'" x-cloak>
                                                        <label class="mb-1 block text-sm font-medium text-slate-700">Body</label>
                                                        <div class="overflow-hidden rounded-md border border-slate-300">
                                                            <div x-ref="matQuillContainer_{{ $material->id }}"
                                                                 data-initial-html="{{ $material->body }}"
                                                                 class="min-h-[200px] bg-white"></div>
                                                        </div>
                                                        <textarea name="body"
                                                                  x-ref="matQuillInput_{{ $material->id }}"
                                                                  x-bind:disabled="matType !== 'text'"
                                                                  class="hidden">{{ $material->body }}</textarea>
                                                    </div>

                                                    <div x-show="matType === 'countdown'" x-cloak>
                                                        <label class="mb-1 block text-sm font-medium text-slate-700">Target date</label>
                                                        <input type="text" name="target_date" data-flatpickr
                                                               value="{{ $material->target_date?->format('Y-m-d H:i') }}"
                                                               placeholder="Y-m-d H:i"
                                                               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                                                    </div>

                                                    <div>
                                                        <label class="mb-1 block text-sm font-medium text-slate-700">Sort order</label>
                                                        <input type="number" name="sort_order" min="0" value="{{ $material->sort_order }}"
                                                               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                                                    </div>

                                                    <label class="flex items-center gap-2 text-sm text-slate-700">
                                                        <input type="hidden" name="is_published" value="0">
                                                        <input type="checkbox" name="is_published" value="1"
                                                               @checked($material->is_published)>
                                                        Published
                                                    </label>

                                                    <div class="flex items-center justify-between pt-2">
                                                        <button type="submit"
                                                                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                                                            Save
                                                        </button>
                                                        <button type="button" @click="openMaterial = null"
                                                                class="rounded-md px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">
                                                            Cancel
                                                        </button>
                                                    </div>
                                                </form>

                                                <form method="POST" action="{{ route('materials.destroy', $material) }}"
                                                      onsubmit="return confirm('Delete this material?');"
                                                      class="mt-4 border-t border-slate-200 pt-4">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="text-sm text-red-600 hover:underline">Delete material</button>
                                                </form>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <div class="border-t border-slate-100 bg-slate-50 px-4 py-2 text-right">
                                <a href="{{ route('materials.create', $section) }}"
                                   class="text-xs font-medium text-slate-700 hover:underline">
                                    + Add resource
                                </a>
                            </div>
                        </article>

                        {{-- + button below each section (insert next) --}}
                        <form method="POST" action="{{ route('sections.quick-insert', $course) }}">
                            @csrf
                            <input type="hidden" name="position" value="below">
                            <input type="hidden" name="ref_section_id" value="{{ $section->id }}">
                            <button type="submit"
                                    class="group flex w-full items-center justify-center rounded-md border border-dashed border-slate-300 py-2 text-xs font-medium text-slate-500 transition hover:border-slate-400 hover:bg-slate-50 hover:text-slate-700">
                                <span class="group-hover:opacity-100">+ Insert section here</span>
                            </button>
                        </form>

                        {{-- Edit modal for this section --}}
                        <div x-show="openSection === {{ $section->id }}" x-cloak
                             class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto p-4">
                            <div @click="openSection = null"
                                 x-show="openSection === {{ $section->id }}" x-cloak
                                 class="fixed inset-0 bg-black/40"></div>
                            <div x-show="openSection === {{ $section->id }}" x-cloak
                                 class="relative mt-12 w-full max-w-xl rounded-lg bg-white p-6 shadow-xl">
                                <div class="mb-4 flex items-center justify-between">
                                    <h3 class="text-lg font-semibold text-slate-900">Edit section</h3>
                                    <button type="button" @click="openSection = null"
                                            class="text-slate-400 hover:text-slate-600">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>

                                <form method="POST" action="{{ route('sections.update', $section) }}"
                                      class="space-y-4">
                                    @csrf @method('PATCH')

                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Title</label>
                                        <input type="text" name="title" required
                                               value="{{ $section->title }}"
                                               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
                                    </div>

                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Available from (optional)</label>
                                        <input type="text" name="scheduled_at" data-flatpickr
                                               x-ref="scheduledAt"
                                               @change="if ($event.target.value && $refs.publishedCheckbox) $refs.publishedCheckbox.checked = false"
                                               value="{{ $section->scheduled_at?->format('Y-m-d H:i') }}"
                                               placeholder="Y-m-d H:i"
                                               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                                        <p class="mt-1 text-xs text-slate-500">Hidden from students until this moment. Leave empty to publish immediately.</p>
                                    </div>

                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Sort order</label>
                                        <input type="number" name="sort_order" min="0"
                                               value="{{ $section->sort_order }}"
                                               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                                    </div>

                                    <label class="flex items-center gap-2 text-sm text-slate-700">
                                        {{-- Hidden 0 ensures we receive a value when the checkbox is unticked. --}}
                                        <input type="hidden" name="is_published" value="0">
                                        <input type="checkbox" name="is_published" value="1"
                                               x-ref="publishedCheckbox"
                                               @checked($section->is_published)
                                               @change="if ($event.target.checked && $refs.scheduledAt?._flatpickr) $refs.scheduledAt._flatpickr.clear()">
                                        Published
                                    </label>

                                    <div class="flex items-center justify-between pt-2">
                                        <button type="submit"
                                                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                                            Save
                                        </button>
                                        <button type="button" @click="openSection = null"
                                                class="rounded-md px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">
                                            Cancel
                                        </button>
                                    </div>
                                </form>

                                <form method="POST" action="{{ route('sections.destroy', $section) }}"
                                      onsubmit="return confirm('Delete this section and all its materials?');"
                                      class="mt-4 border-t border-slate-200 pt-4">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-sm text-red-600 hover:underline">Delete section</button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
        @endif
    </div>
@endsection

@push('head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css">
    <style>
        /* Mirror the public renderer so image alignment is visible while
           editing — Quill aligns the paragraph but img stays inline by
           default, so we force block + auto margins for the alignment
           classes. */
        .ql-editor .ql-align-center img { display: block; margin-left: auto; margin-right: auto; }
        .ql-editor .ql-align-right img  { display: block; margin-left: auto; margin-right: 0; }
        .ql-editor .ql-align-justify img{ display: block; margin-left: auto; margin-right: auto; }
        .ql-editor img { max-width: 100%; height: auto; }
    </style>
    <style>
        .ts-wrapper { padding: 0 !important; border: 0 !important; box-shadow: none !important; }
        .ts-wrapper.single .ts-control,
        .ts-wrapper.single.input-active .ts-control {
            border: 1px solid rgb(203 213 225) !important;
            border-radius: 0.375rem;
            padding: 0.375rem 0.75rem;
            min-height: 2.25rem;
            font-size: 0.875rem;
            background: #fff;
            box-shadow: none;
        }
        .ts-wrapper.single.focus .ts-control {
            border-color: rgb(100 116 139) !important;
            box-shadow: 0 0 0 1px rgb(100 116 139);
        }
        .ts-wrapper.single .ts-control input {
            font-size: 0.875rem;
            border: 0 !important;
            outline: 0 !important;
            box-shadow: none !important;
            background: transparent !important;
        }
        .ts-dropdown { font-size: 0.875rem; border-color: rgb(203 213 225); }
    </style>
@endpush

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.min.js"></script>
    <script>
        // Initialize a Quill rich-text editor on the given container, syncing
        // its HTML output back into the hidden textarea so the form submits
        // the right value. Idempotent — re-calls are safe.
        window.initQuillEditor = function (container, mirrorInput) {
            if (!container || container.dataset.quillReady === '1') return;
            container.dataset.quillReady = '1';

            const editor = new Quill(container, {
                theme: 'snow',
                placeholder: 'Write something…',
                modules: {
                    toolbar: {
                        container: [
                            [{ header: [1, 2, 3, false] }],
                            ['bold', 'italic', 'underline', 'strike'],
                            [{ list: 'ordered' }, { list: 'bullet' }],
                            [{ align: [] }],
                            ['blockquote'],
                            ['link', 'image'],
                        ],
                        handlers: {
                            image: function () {
                                const input = document.createElement('input');
                                input.type = 'file';
                                input.accept = 'image/*';
                                input.click();

                                input.onchange = async () => {
                                    const file = input.files[0];
                                    if (!file) return;

                                    const form = new FormData();
                                    form.append('image', file);

                                    try {
                                        const res = await fetch('{{ route('sections.upload-image') }}', {
                                            method: 'POST',
                                            headers: {
                                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                                'Accept': 'application/json',
                                            },
                                            body: form,
                                        });
                                        if (!res.ok) throw new Error('Upload failed (' + res.status + ')');
                                        const data = await res.json();
                                        const range = editor.getSelection(true);
                                        editor.insertEmbed(range.index, 'image', data.url, 'user');
                                        editor.setSelection(range.index + 1);
                                    } catch (e) {
                                        alert('Image upload failed: ' + e.message);
                                    }
                                };
                            },
                        },
                    },
                },
            });

            // Seed with the existing content stored on the container.
            const initial = container.dataset.initialHtml || mirrorInput.value || '';
            if (initial) {
                editor.clipboard.dangerouslyPasteHTML(initial);
            }

            // Keep the hidden textarea in lockstep with the editor so form
            // submits the latest HTML.
            editor.on('text-change', () => {
                mirrorInput.value = editor.root.innerHTML;
            });
        };
    </script>
    <script>
        window.addMonths = function (dateStr, months) {
            if (!dateStr) return '';
            const [datePart, timePart = '00:00'] = dateStr.split(' ');
            const [y, m, d] = datePart.split('-').map(Number);
            const [h, min] = timePart.split(':').map(Number);
            const dt = new Date(y, m - 1, d, h, min);
            dt.setMonth(dt.getMonth() + months);
            return dt.getFullYear() + '-'
                + String(dt.getMonth() + 1).padStart(2, '0') + '-'
                + String(dt.getDate()).padStart(2, '0') + ' '
                + String(dt.getHours()).padStart(2, '0') + ':'
                + String(dt.getMinutes()).padStart(2, '0');
        };
        document.addEventListener('DOMContentLoaded', () => {
            flatpickr('[data-flatpickr]', {
                enableTime: true,
                time_24hr: true,
                dateFormat: 'Y-m-d H:i',
                minuteIncrement: 5,
                allowInput: false,
            });
            document.querySelectorAll('[data-search-select]').forEach(el => {
                new TomSelect(el, { create: false, allowEmptyOption: true });
            });
        });
    </script>
@endpush
