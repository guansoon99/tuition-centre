@extends('layouts.app')

@section('title', 'Courses')

@section('content')
    @php
        $isAdmin = auth()->user()->hasRole('admin');
        // Anyone with any course-management perm can open the edit page.
        $canEditCourse = auth()->user()->canAny([
            'courses.manage_teachers',
            'courses.manage_students',
            'sections.manage',
        ]);
        // Permanent bulk-delete is gated by its own permission. Only
        // holders of `courses.delete` see the checkboxes and Delete button.
        $canBulkDelete = auth()->user()->can('courses.delete');
    @endphp
    <div class="space-y-6"
         x-data="{
             selected: [],
             toggleAll(checked) {
                 this.selected = checked ? Array.from(document.querySelectorAll('input.course-checkbox')).map(el => el.value) : [];
             },
             deleteSelected() {
                 if (! this.selected.length) return;
                 const msg = `Permanently delete ${this.selected.length} course(s)?\n\n`
                     + `This will also destroy every section, material, enrollment and uploaded file. This is NOT reversible.`;
                 if (! confirm(msg)) return;
                 this.$refs.bulkDeleteForm.submit();
             },
         }">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold text-slate-900">Courses</h1>
            <div class="flex gap-2">
                @if ($canBulkDelete)
                    {{-- Delete button: always visible, disabled until at least one row is ticked. --}}
                    <button type="button" @click="deleteSelected()"
                            :disabled="selected.length === 0"
                            class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-red-300 disabled:hover:bg-red-300">
                        Delete
                    </button>
                @endif
                @if ($isAdmin)
                    <a href="{{ route('courses.create') }}"
                       class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                        + New course
                    </a>
                @endif
            </div>
        </div>

        {{-- Hidden form the bulk-delete button submits. Uses selected array
             from Alpine, serialized as ids[] via a template loop. --}}
        @if ($canBulkDelete)
            <form method="POST" action="{{ route('courses.bulk-destroy') }}" x-ref="bulkDeleteForm" class="hidden">
                @csrf
                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="ids[]" :value="id">
                </template>
            </form>
        @endif

        <form method="GET" action="{{ route('courses.index') }}"
              x-data
              x-init="
                  if (sessionStorage.getItem('courses-q-focus') === '1') {
                      sessionStorage.removeItem('courses-q-focus');
                      const input = $el.querySelector('input[name=q]');
                      if (input) {
                          input.focus();
                          input.setSelectionRange(input.value.length, input.value.length);
                      }
                  }
              "
              class="flex flex-wrap gap-3 rounded-md border border-slate-200 bg-white p-3">
            <input type="text" name="q" placeholder="Search code or name"
                   value="{{ $filters['q'] ?? '' }}"
                   @input.debounce.500ms="sessionStorage.setItem('courses-q-focus', '1'); $el.form.submit()"
                   class="flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />

            <select name="active" onchange="this.form.submit()"
                    class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                <option value="">All Status</option>
                <option value="1" @selected(($filters['active'] ?? '') === '1')>Active</option>
                <option value="0" @selected(($filters['active'] ?? '') === '0')>Inactive</option>
            </select>

            <a href="{{ route('courses.index') }}" class="rounded-md bg-red-500 px-3 py-1.5 text-sm text-white hover:bg-red-600">Clear</a>
        </form>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="w-full min-w-[720px] text-sm [&_td]:whitespace-nowrap [&_th]:whitespace-nowrap">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-800">
                    <tr>
                        @if ($canBulkDelete)
                            <th class="w-8 px-4 py-3">
                                {{-- Select-all header checkbox — only shown for users with courses.delete. --}}
                                <input type="checkbox" @change="toggleAll($event.target.checked)"
                                       class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" />
                            </th>
                        @endif
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3 text-right">Teachers</th>
                        <th class="px-4 py-3 text-right">Students</th>
                        <th class="px-4 py-3 text-right">Sections</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Created</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($courses as $c)
                        <tr>
                            @if ($canBulkDelete)
                                <td class="px-4 py-3">
                                    <input type="checkbox" x-model="selected" value="{{ $c->id }}"
                                           class="course-checkbox rounded border-slate-300 text-slate-900 focus:ring-slate-500" />
                                </td>
                            @endif
                            <td class="px-4 py-3 font-mono text-slate-800">{{ $c->code }}</td>
                            <td class="px-4 py-3 text-slate-800">{{ $c->name }}</td>
                            <td class="px-4 py-3 text-right text-slate-800">{{ $c->teachers_count }}</td>
                            <td class="px-4 py-3 text-right text-slate-800">{{ $c->students_count }}</td>
                            <td class="px-4 py-3 text-right text-slate-800">{{ $c->sections_count }}</td>
                            <td class="px-4 py-3">
                                @if ($c->is_active)
                                    <span class="inline-flex min-w-[72px] items-center justify-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">
                                        <span class="mr-1 h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Active
                                    </span>
                                @else
                                    <span class="inline-flex min-w-[72px] items-center justify-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                        <span class="mr-1 h-1.5 w-1.5 rounded-full bg-red-500"></span>Inactive
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-sm">
                                {{ $c->created_at->format('Y-m-d H:i') }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('courses.show', $c) }}"
                                       class="rounded-md bg-sky-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-sky-700">
                                        View
                                    </a>
                                    @if ($canEditCourse)
                                        <a href="{{ route('courses.edit', $c) }}"
                                           class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-emerald-700">
                                            Edit
                                        </a>
                                    @endif
                                    @can('courses.activate')
                                        @if ($c->is_active)
                                            <form method="POST" action="{{ route('courses.destroy', $c) }}"
                                                  onsubmit="return confirm('Deactivate {{ $c->code }}?');">
                                                @csrf @method('DELETE')
                                                <button type="submit"
                                                        class="min-w-[88px] rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-red-700">
                                                    Deactivate
                                                </button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('courses.activate', $c) }}">
                                                @csrf
                                                <button type="submit"
                                                        class="min-w-[88px] rounded-md bg-amber-500 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-amber-600">
                                                    Activate
                                                </button>
                                            </form>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canBulkDelete ? 9 : 8 }}" class="px-4 py-8 text-center text-sm text-slate-400">No courses.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $courses->links() }}</div>
    </div>
@endsection
