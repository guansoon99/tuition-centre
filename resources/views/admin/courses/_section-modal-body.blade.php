{{--
    Body of the "Edit section" modal on the Materials tab, fetched from
    sections.edit-modal when the modal opens.

    It used to be rendered inline once per section. On a school-year course
    that was 25 complete forms — each with a date picker, a status select and
    two submit forms — sitting hidden in the page on every load, 157 KB of
    the 790 KB the tab weighed. Now the page carries one empty shell and the
    form arrives when someone actually clicks Edit, the same arrangement the
    material modal already uses.

    Returns a bare fragment: no layout, and no @push — the page has already
    rendered its head by the time this is fetched, so anything pushed here
    would be discarded silently. The shell that receives it initialises
    flatpickr on [data-flatpickr] and walks the subtree with Alpine.

    The close buttons set openSection on the page's root x-data; that
    resolves through the injected subtree's ancestors, as it does for the
    material modal.

    Expects: $section
--}}
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
                                                   @change="hasDate = !! $event.target.value; if ($event.target.value && $root.$refs.publishedStatus) $root.$refs.publishedStatus.value = '0'"
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

                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Status</label>
                                        {{-- A select always submits a value, so unlike a checkbox it needs
                                             no hidden companion to make "off" arrive. --}}
                                        <select name="is_published"
                                                x-ref="publishedStatus"
                                                @change="if ($event.target.value === '1' && $refs.scheduledAt?._flatpickr) $refs.scheduledAt._flatpickr.clear()"
                                                class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                                            <option value="1" @selected($section->is_published)>Published</option>
                                            <option value="0" @selected(! $section->is_published)>Unpublished</option>
                                        </select>
                                    </div>

                                    <label class="flex items-center gap-2 text-sm text-slate-700">
                                        {{-- Hidden 0 ensures we receive a value when the checkbox is unticked. --}}
                                        <input type="hidden" name="never_collapses" value="0">
                                        <input type="checkbox" name="never_collapses" value="1"
                                               @checked($section->never_collapses)>
                                        Always open
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
