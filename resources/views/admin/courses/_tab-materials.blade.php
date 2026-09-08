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
                        <span x-text="reason || &quot;Couldn't load this material.&quot;"></span>
                        <button type="button" x-show="! reason" @click="load(openMaterial)" class="underline">Retry</button>
                    </div>
                    {{-- Fetched markup lands here. --}}
                    <div x-ref="body" x-show="! loading && ! failed"></div>
                </div>
            </div>

            {{-- One shared edit-section modal for the whole page, body fetched
                 from sections.edit-modal on open. Same loader as the material
                 modal above; only the URL differs. --}}
            <div x-show="openSection !== null" x-cloak
                 x-data="materialEditModal()"
                 x-init="
                     $watch('openSection', id => id === null ? reset() : load(id, '/sections/{id}/edit-modal'));
                     if (openSection !== null) load(openSection, '/sections/{id}/edit-modal');
                 "
                 class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto p-4">
                <div @click="openSection = null" class="fixed inset-0 bg-black/40"></div>
                <div class="relative mt-12 w-full max-w-xl rounded-lg bg-white p-6 shadow-xl">
                    <div x-show="loading" class="py-10 text-center text-sm text-slate-600">Loading…</div>
                    <div x-show="failed" x-cloak class="py-10 text-center text-sm text-red-600">
                        <span x-text="reason || &quot;Couldn't load this section.&quot;"></span>
                        <button type="button" x-show="! reason" @click="load(openSection, '/sections/{id}/edit-modal')" class="underline">Retry</button>
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
                        <span x-text="reason || &quot;Couldn't load the form.&quot;"></span>
                        <button type="button" x-show="! reason"
                                @click="load(openNewMaterialFor, '/sections/{id}/materials/create-modal')"
                                class="underline">Retry</button>
                    </div>
                    <div x-ref="body" x-show="! loading && ! failed"></div>
                </div>
            </div>

            {{-- One resource menu for the whole tab: Edit / Move right / Move left /
                 Hide (or Show) / Delete. A row's button dispatches 'material-menu'
                 with its id, indent and published flag; the menu opens under that
                 button and builds its form actions from the URL templates here, so
                 the routes stay the source of truth. Fixed-positioned and closed on
                 scroll, so it never needs the row's stacking context. --}}
            <div x-data="materialMenu()"
                 @material-menu.window="show($event.detail)"
                 @click.outside="close()"
                 @keydown.escape.window="close()"
                 @scroll.window="close()"
                 @resize.window="close()"
                 x-show="item !== null" x-cloak
                 :style="style"
                 data-url-move-right="{{ route('materials.move-right', ['material' => '__ID__']) }}"
                 data-url-move-left="{{ route('materials.move-left', ['material' => '__ID__']) }}"
                 data-url-hide="{{ route('materials.hide', ['material' => '__ID__']) }}"
                 data-url-unhide="{{ route('materials.unhide', ['material' => '__ID__']) }}"
                 data-url-destroy="{{ route('materials.destroy', ['material' => '__ID__']) }}"
                 class="fixed z-30 w-44 rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg ring-1 ring-black/5">
                <button type="button" @click="openMaterial = item.id; close()"
                        class="block w-full px-4 py-2 text-left text-slate-800 hover:bg-slate-50">
                    Edit
                </button>
                <form method="POST" :action="url('move-right')" x-show="item && item.indent < {{ \App\Models\Material::MAX_INDENT }}">
                    @csrf
                    <button type="submit" class="block w-full px-4 py-2 text-left text-slate-800 hover:bg-slate-50">Move right</button>
                </form>
                <form method="POST" :action="url('move-left')" x-show="item && item.indent > 0">
                    @csrf
                    <button type="submit" class="block w-full px-4 py-2 text-left text-slate-800 hover:bg-slate-50">Move left</button>
                </form>
                <form method="POST" :action="url(item && item.published ? 'hide' : 'unhide')">
                    @csrf
                    <button type="submit" class="block w-full px-4 py-2 text-left text-slate-800 hover:bg-slate-50"
                            x-text="item && item.published ? 'Hide' : 'Show'"></button>
                </form>
                <form method="POST" :action="url('destroy')"
                      onsubmit="return confirm('Delete this resource? This cannot be undone.');">
                    @csrf @method('DELETE')
                    <button type="submit" class="block w-full px-4 py-2 text-left text-red-700 hover:bg-red-50">Delete</button>
                </form>
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
                        {{-- No overflow-hidden on the card: the section menu below drops
                             outside the header, and clipping it is exactly the bug. The
                             header rounds its own top corners instead. --}}
                        <article class="rounded-lg border border-slate-200 bg-white">
                            <header class="rounded-t-lg border-b border-slate-100 bg-slate-50 px-4 py-3">
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
                                    {{-- Section menu: Edit / Hide (or Show) / Delete behind one
                                         three-dot button. --}}
                                    <div class="relative" x-data="{ menuOpen: false }"
                                         @click.outside="menuOpen = false"
                                         @keydown.escape.window="menuOpen = false">
                                        <button type="button" @click="menuOpen = ! menuOpen"
                                                title="Section actions" aria-label="Section actions"
                                                :aria-expanded="menuOpen"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 hover:bg-slate-100">
                                            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <circle cx="4" cy="10" r="1.75" /><circle cx="10" cy="10" r="1.75" /><circle cx="16" cy="10" r="1.75" />
                                            </svg>
                                        </button>
                                        <div x-show="menuOpen" x-cloak
                                             class="absolute right-0 top-full z-20 mt-1 w-44 rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg ring-1 ring-black/5">
                                            <button type="button"
                                                    @click="openSection = {{ $section->id }}; menuOpen = false"
                                                    class="block w-full px-4 py-2 text-left text-slate-800 hover:bg-slate-50">
                                                Edit Section
                                            </button>
                                            <form method="POST" action="{{ route($section->is_published ? 'sections.hide' : 'sections.unhide', $section) }}">
                                                @csrf
                                                <button type="submit"
                                                        class="block w-full px-4 py-2 text-left text-slate-800 hover:bg-slate-50">
                                                    {{ $section->is_published ? 'Hide Section' : 'Show Section' }}
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('sections.destroy', $section) }}"
                                                  onsubmit="return confirm('Delete this section and everything in it? This cannot be undone.');">
                                                @csrf @method('DELETE')
                                                <button type="submit"
                                                        class="block w-full px-4 py-2 text-left text-red-700 hover:bg-red-50">
                                                    Delete Section
                                                </button>
                                            </form>
                                        </div>
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
                                        <div class="flex items-center gap-1 py-2 pr-3" data-material-id="{{ $material->id }}"
                                             @if ($material->indent) style="padding-left: {{ $material->indent * 1.5 }}rem" @endif>
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
                                            @unless ($material->is_published)
                                                <span class="rounded bg-amber-100 px-1.5 text-xs text-amber-800">Hidden</span>
                                            @endunless
                                            {{-- The row's edit button opens the one shared resource menu (below).
                                                 Per row this is a button and four numbers; the menu,
                                                 its forms and its CSRF tokens exist once per page, which
                                                 is what keeps the tab's weight flat as materials grow. --}}
                                            <button type="button" title="Edit material" aria-label="Edit material"
                                                    @click.stop="$dispatch('material-menu', { anchor: $event.currentTarget, id: {{ $material->id }}, indent: {{ $material->indent }}, published: {{ $material->is_published ? 'true' : 'false' }} })"
                                                    class="inline-flex items-center justify-center rounded-md bg-slate-100 p-1.5 text-slate-700 hover:bg-slate-200 hover:text-slate-900">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
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
                    @endforeach
                </div>
            @endif
        </section>
