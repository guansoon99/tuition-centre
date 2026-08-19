        <section x-show="tab === 'materials'" x-cloak class="space-y-4">
            {{--
                One shared edit-material modal for the whole page. Its body is
                fetched from materials.edit-modal when opened.

                Previously this markup was repeated per material: a 72-material
                course shipped ~1.8 MB of HTML and, because each copy's x-init
                ran on page load, created up to 72 hidden Quill editors before
                the user touched anything.
            --}}
            <div x-show="openMaterial !== null" x-cloak
                 x-data="materialEditModal()"
                 x-init="
                     $watch('openMaterial', id => id === null ? reset() : load(id));
                     if (openMaterial !== null) load(openMaterial);
                 "
                 class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto p-4">
                <div @click="openMaterial = null" class="fixed inset-0 bg-black/40"></div>

                <div class="relative mt-12 w-full max-w-xl rounded-lg bg-white p-6 shadow-xl">
                    <div x-show="loading" class="py-10 text-center text-sm text-slate-600">Loading…</div>
                    <div x-show="failed" x-cloak class="py-10 text-center text-sm text-red-600">
                        Couldn't load this material.
                        <button type="button" @click="load(openMaterial)" class="underline">Retry</button>
                    </div>
                    {{-- Fetched markup lands here. --}}
                    <div x-ref="body" x-show="! loading && ! failed"></div>
                </div>
            </div>

            {{-- Shared "Add Resource" modal, one per page rather than one per
                 section. Body fetched from materials.create-modal on open. --}}
            <div x-show="openNewMaterialFor !== null" x-cloak
                 x-data="materialEditModal()"
                 x-init="
                     $watch('openNewMaterialFor', id => id === null
                         ? reset()
                         : load(id, '/sections/{id}/materials/create-modal'));
                 "
                 class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto p-4">
                <div @click="openNewMaterialFor = null" class="fixed inset-0 bg-black/40"></div>

                <div class="relative mt-12 w-full max-w-xl rounded-lg bg-white p-6 shadow-xl">
                    <div x-show="loading" class="py-10 text-center text-sm text-slate-600">Loading…</div>
                    <div x-show="failed" x-cloak class="py-10 text-center text-sm text-red-600">
                        Couldn't load the form.
                        <button type="button"
                                @click="load(openNewMaterialFor, '/sections/{id}/materials/create-modal')"
                                class="underline">Retry</button>
                    </div>
                    <div x-ref="body" x-show="! loading && ! failed"></div>
                </div>
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
                                class="group flex w-full items-center justify-center rounded-md border border-dashed border-slate-300 py-2 text-sm font-medium text-slate-500 transition hover:border-slate-400 hover:bg-slate-50 hover:text-slate-700">
                            <span class="group-hover:opacity-100">+ Insert section here</span>
                        </button>
                    </form>

                    @foreach ($course->sections as $section)
                        <article class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                            <header class="border-b border-slate-100 bg-slate-50 px-4 py-3">
                                <div class="flex items-baseline justify-between gap-2">
                                    <h2 class="text-lg font-semibold text-black">
                                        {{ $section->title }}
                                        @if ($section->scheduled_at && $section->scheduled_at->isFuture())
                                            <span class="ml-1 rounded bg-sky-100 px-1.5 font-mono text-xs text-sky-700"
                                                  title="Goes live at {{ $section->scheduled_at->format('Y-m-d H:i') }}">
                                                Scheduled
                                            </span>
                                        @elseif (! $section->is_published && ! $section->scheduled_at)
                                            <span class="ml-1 rounded bg-amber-100 px-1.5 text-xs text-amber-800">Draft</span>
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

                            @php
                                $addResourceClass = 'group flex w-full items-center gap-3 py-2 text-sm font-semibold text-slate-800 transition hover:text-slate-900';
                                $addResourceLine  = 'flex-1 border-t border-dashed border-slate-300 group-hover:border-slate-400';
                                $addResourceLabel = 'rounded-md bg-slate-100 px-3 py-1 group-hover:bg-slate-200';
                            @endphp

                            <div class="space-y-2 border-t border-slate-100 px-3 py-3">
                            @if ($section->materials->isEmpty())
                                <p class="px-1 py-2 text-sm text-black">No resources yet.</p>
                            @else
                                <div class="divide-y divide-slate-300"
                                     data-sortable-materials
                                     data-section-id="{{ $section->id }}">
                                    @foreach ($section->materials as $material)
                                        <div class="flex items-center gap-1 py-2 pr-3" data-material-id="{{ $material->id }}">
                                            {{-- Drag handle --}}
                                            <button type="button"
                                                    title="Drag to reorder"
                                                    class="material-drag-handle cursor-grab px-2 text-black active:cursor-grabbing">
                                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                                                    <path d="M7 4a1 1 0 100 2 1 1 0 000-2zM7 9a1 1 0 100 2 1 1 0 000-2zM7 14a1 1 0 100 2 1 1 0 000-2zM13 4a1 1 0 100 2 1 1 0 000-2zM13 9a1 1 0 100 2 1 1 0 000-2zM13 14a1 1 0 100 2 1 1 0 000-2z" />
                                                </svg>
                                            </button>
                                            {{-- min-w-0 is load-bearing: a flex item defaults to
                                                 min-width:auto, so without it this refuses to shrink
                                                 below its content's intrinsic width and a long
                                                 unbreakable run of text pushes the edit button off
                                                 the row. --}}
                                            <div class="min-w-0 flex-1">@include('partials.material-item', ['material' => $material])</div>
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
                                    @endforeach
                                </div>
                            @endif

                                {{-- Add resource button at the BOTTOM of the list --}}
                                <button type="button"
                                        @click="openNewMaterialFor = {{ $section->id }}"
                                        class="{{ $addResourceClass }}">
                                    <span class="{{ $addResourceLine }}"></span>
                                    <span class="{{ $addResourceLabel }}">+ Add Resource</span>
                                    <span class="{{ $addResourceLine }}"></span>
                                </button>
                            </div>

                        </article>

                        {{-- + button below each section (insert next) --}}
                        <form method="POST" action="{{ route('sections.quick-insert', $course) }}">
                            @csrf
                            <input type="hidden" name="position" value="below">
                            <input type="hidden" name="ref_section_id" value="{{ $section->id }}">
                            <button type="submit"
                                    class="group flex w-full items-center justify-center rounded-md border border-dashed border-slate-300 py-2 text-sm font-medium text-slate-500 transition hover:border-slate-400 hover:bg-slate-50 hover:text-slate-700">
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
                                        <label class="mb-1 block text-sm font-medium text-slate-700">
                                            Available from <span class="font-normal text-slate-600">(optional)</span>
                                        </label>
                                        {{-- Local x-data so hasDate is reactive to input/clear changes.
                                             Reading $refs.el.value directly isn't reactive — Alpine can't
                                             re-evaluate x-show when a DOM property changes. --}}
                                        <div class="relative"
                                             x-data="{ hasDate: {{ $section->scheduled_at ? 'true' : 'false' }} }">
                                            <input type="text" name="scheduled_at" data-flatpickr
                                                   x-ref="scheduledAt"
                                                   @change="hasDate = !! $event.target.value; if ($event.target.value && $root.$refs.publishedCheckbox) $root.$refs.publishedCheckbox.checked = false"
                                                   @input="hasDate = !! $event.target.value"
                                                   value="{{ $section->scheduled_at?->format('Y-m-d H:i') }}"
                                                   placeholder="Y-m-d H:i"
                                                   class="w-full rounded-md border border-slate-300 px-3 py-2 pr-9 text-sm" />
                                            <button type="button"
                                                    x-show="hasDate" x-cloak
                                                    @click="$refs.scheduledAt._flatpickr?.clear(); $refs.scheduledAt.value = ''; hasDate = false"
                                                    title="Clear date"
                                                    class="absolute inset-y-0 right-0 flex items-center pr-2">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
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
                                        <button type="button" @click="openSection = null"
                                                class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
                                            Cancel
                                        </button>
                                        <button type="submit"
                                                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                                            Save
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
