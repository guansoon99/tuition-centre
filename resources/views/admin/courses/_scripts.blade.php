@include('partials.course-video-uploader')
    @vite('resources/js/sortable.js')
    <script>
        // Asked after a teacher picks an image for a material: show it at its
        // own size, or at a width/height they type, with proportions kept
        // unless they untick the box. Resolves to
        //   { width, height }  — height is null when proportions are kept, so
        //                        the browser works it out from the width
        //   null               — cancelled; nothing is uploaded
        // It sets the display size only. The file itself is stored as uploaded.
        window.askImageSize = function (file) {
            return new Promise((resolve) => {
                const url = URL.createObjectURL(file);
                const probe = new Image();
                probe.onload = () => open(probe.naturalWidth, probe.naturalHeight);
                probe.onerror = () => open(0, 0);
                probe.src = url;

                function open(natW, natH) {
                    const known = natW > 0 && natH > 0;
                    const wrap = document.createElement('div');
                    wrap.className = 'fixed inset-0 z-[200] flex items-center justify-center bg-slate-900/50 p-4';
                    wrap.setAttribute('data-image-size-dialog', '');
                    wrap.innerHTML = `
                        <form class="w-full max-w-sm space-y-4 rounded-lg bg-white p-5 text-sm text-slate-800 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="image-size-title">
                            <div class="flex items-center gap-3">
                                <img src="${url}" alt="" class="h-14 w-14 shrink-0 rounded border border-slate-200 object-contain">
                                <div class="min-w-0">
                                    <p id="image-size-title" class="font-semibold text-slate-900">Image size</p>
                                    <p class="truncate text-xs text-slate-600" data-name></p>
                                </div>
                            </div>
                            <label class="flex items-center gap-2">
                                <input type="radio" name="mode" value="original" checked>
                                <span>Original size${known ? ` <span class="text-slate-600">(${natW} × ${natH} px)</span>` : ''}</span>
                            </label>
                            <div class="space-y-3">
                                <label class="flex items-center gap-2">
                                    <input type="radio" name="mode" value="custom">
                                    <span>Custom size</span>
                                </label>
                                <div class="grid grid-cols-2 gap-3 pl-6" data-custom>
                                    <label class="block">
                                        <span class="text-xs font-medium text-slate-700">Width (px)</span>
                                        <input type="number" name="width" min="1" max="5000" step="1" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm disabled:bg-slate-100">
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium text-slate-700">Height (px)</span>
                                        <input type="number" name="height" min="1" max="5000" step="1" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm disabled:bg-slate-100">
                                    </label>
                                    <label class="col-span-2 flex items-center gap-2">
                                        <input type="checkbox" name="keep" checked>
                                        <span>Keep proportions</span>
                                    </label>
                                </div>
                            </div>
                            <p class="text-xs text-slate-600">An image wider than the page shrinks to fit it.</p>
                            <p class="hidden text-xs text-red-600" data-error>Enter a width and height between 1 and 5000.</p>
                            <div class="flex justify-end gap-2">
                                <button type="button" data-cancel class="rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                                <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 font-medium text-white hover:bg-slate-800">Insert image</button>
                            </div>
                        </form>`;
                    wrap.querySelector('[data-name]').textContent = file.name;

                    const form = wrap.querySelector('form');
                    const w = form.elements.width;
                    const h = form.elements.height;
                    const keep = form.elements.keep;
                    const ratio = known ? natW / natH : null;
                    w.value = known ? natW : '';
                    h.value = known ? natH : '';
                    if (! known) keep.checked = false;
                    keep.disabled = ! known;

                    const custom = () => form.elements.mode.value === 'custom';
                    const sync = () => {
                        [w, h, keep].forEach((el) => { el.disabled = ! custom() || (el === keep && ! known); });
                    };
                    sync();
                    form.querySelectorAll('input[name=mode]').forEach((r) => r.addEventListener('change', () => {
                        sync();
                        if (custom()) w.focus();
                    }));
                    w.addEventListener('input', () => {
                        if (keep.checked && ratio && +w.value > 0) h.value = Math.max(1, Math.round(w.value / ratio));
                    });
                    h.addEventListener('input', () => {
                        if (keep.checked && ratio && +h.value > 0) w.value = Math.max(1, Math.round(h.value * ratio));
                    });
                    keep.addEventListener('change', () => {
                        if (keep.checked && ratio && +w.value > 0) h.value = Math.max(1, Math.round(w.value / ratio));
                    });

                    const finish = (value) => {
                        wrap.remove();
                        document.removeEventListener('keydown', onKey, true);
                        URL.revokeObjectURL(url);
                        resolve(value);
                    };
                    const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); finish(null); } };
                    document.addEventListener('keydown', onKey, true);
                    wrap.querySelector('[data-cancel]').addEventListener('click', () => finish(null));
                    wrap.addEventListener('mousedown', (e) => { if (e.target === wrap) finish(null); });

                    form.addEventListener('submit', (e) => {
                        e.preventDefault();
                        if (! custom()) {
                            finish({ width: known ? natW : null, height: null });
                            return;
                        }
                        const width = Math.round(+w.value);
                        const height = Math.round(+h.value);
                        const ok = (n) => Number.isFinite(n) && n >= 1 && n <= 5000;
                        if (! ok(width) || (! keep.checked && ! ok(height))) {
                            wrap.querySelector('[data-error]').classList.remove('hidden');
                            return;
                        }
                        finish({ width, height: keep.checked ? null : height });
                    });

                    document.body.appendChild(wrap);
                    form.querySelector('input[name=mode]:checked').focus();
                }
            });
        };

        // Gives an image just inserted into a Quill editor the size picked in
        // askImageSize. The width is the image's width attribute. A typed
        // height (proportions unticked) is also an inline style: the page's
        // base styles set every image's height to auto, and only an inline
        // style beats that. Quill keeps both (see quill.js), and so does the
        // sanitiser on save.
        window.applyImageSize = function (editor, index, size) {
            if (! size || ! size.width) return;
            const formats = { width: String(size.width) };
            if (size.height) {
                formats.height = String(size.height);
                formats.style = 'height: ' + size.height + 'px';
            }
            editor.formatText(index, 1, formats, 'user');
        };
    </script>
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
                        container: [[
                            // All buttons in one .ql-formats group — no visual
                            // separators, wraps individually rather than by group.
                            { header: [1, 2, 3, false] },
                            'bold', 'italic', 'underline', 'strike',
                            { color: [] }, { background: [] },
                            { list: 'ordered' }, { list: 'bullet' },
                            { align: [] },
                            'blockquote',
                            'link', 'image', 'video',
                        ]],
                        handlers: {
                            image: function () {
                                const input = document.createElement('input');
                                input.type = 'file';
                                input.accept = 'image/jpeg,image/png,image/webp';
                                input.click();

                                input.onchange = async () => {
                                    const file = input.files[0];
                                    if (!file) return;

                                    // Before uploading, so Cancel costs nothing.
                                    const size = await window.askImageSize(file);
                                    if (size === null) return;

                                    const ui = window.courseMediaOverlay(editor.root, 'Uploading image…');
                                    const form = new FormData();
                                    form.append('image', file);

                                    try {
                                        const res = await fetch('{{ route('course-media.upload-image', $course) }}', {
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
                                        window.applyImageSize(editor, range.index, size);
                                        editor.setSelection(range.index + 1);
                                    } catch (e) {
                                        alert('Image upload failed: ' + e.message);
                                    } finally {
                                        ui.done();
                                    }
                                };
                            },
                            video: function () {
                                const input = document.createElement('input');
                                input.type = 'file';
                                input.accept = 'video/mp4,video/webm,video/quicktime';
                                input.click();

                                input.onchange = async () => {
                                    const file = input.files[0];
                                    if (!file) return;

                                    const ui = window.courseMediaOverlay(editor.root, 'Uploading video…');

                                    try {
                                        const url = await window.uploadCourseVideo({
                                            presign:  '{{ route('course-media.presign-video', $course) }}',
                                            register: '{{ route('course-media.register-video', $course) }}',
                                            upload:   '{{ route('course-media.upload-video', $course) }}',
                                            maxMb:    {{ \App\Http\Controllers\CourseMediaController::MAX_VIDEO_MB }},
                                        }, file, ui.progress);

                                        // Insert an HTML5 <video> tag at the cursor. Quill's built-in
                                        // video embed uses <iframe> (for YouTube-style URLs) — we want
                                        // native playback for uploaded files.
                                        // A block embed, not pasted HTML: the nativeVideo blot
                                        // (quill-native-video.js) owns the <video> element, so it
                                        // survives here and again when the body is loaded back
                                        // for editing.
                                        const range = editor.getSelection(true);
                                        editor.insertEmbed(range.index, 'nativeVideo', url, 'user');
                                        editor.setSelection(range.index + 1, 0, 'silent');
                                    } catch (e) {
                                        alert('Video upload failed: ' + e.message);
                                    } finally {
                                        ui.done();
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

            // Inject a custom "Insert table" button into the Quill toolbar.
            // Clicking it opens a small hover-grid picker (like Google Docs)
            // so users can size the table visually. No browser prompts.
            const toolbarEl = editor.getModule('toolbar').container;
            if (toolbarEl && ! toolbarEl.querySelector('.ql-table-insert')) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.title = 'Insert table';
                btn.className = 'ql-table-insert';
                btn.innerHTML = '<svg viewBox="0 0 18 18" style="width:18px;height:18px"><rect x="2" y="3" width="14" height="12" fill="none" stroke="currentColor" stroke-width="1.6"/><line x1="2" y1="7.5" x2="16" y2="7.5" stroke="currentColor" stroke-width="1.6"/><line x1="2" y1="11.5" x2="16" y2="11.5" stroke="currentColor" stroke-width="1.6"/><line x1="6.5" y1="3" x2="6.5" y2="15" stroke="currentColor" stroke-width="1.6"/><line x1="11.5" y1="3" x2="11.5" y2="15" stroke="currentColor" stroke-width="1.6"/></svg>';

                btn.onclick = (e) => {
                    e.stopPropagation();
                    // Close any picker that's already open.
                    document.querySelectorAll('.ql-table-picker').forEach(el => el.remove());

                    const maxRows = 8, maxCols = 10;
                    const picker = document.createElement('div');
                    picker.className = 'ql-table-picker';
                    picker.style.cssText = 'position:absolute; z-index:60; background:#fff; border:1px solid rgb(203 213 225); border-radius:6px; padding:10px; box-shadow:0 8px 24px rgba(0,0,0,0.12); user-select:none;';

                    // Position under the toolbar button.
                    const rect = btn.getBoundingClientRect();
                    picker.style.top = (rect.bottom + window.scrollY + 4) + 'px';
                    picker.style.left = (rect.left + window.scrollX) + 'px';

                    const label = document.createElement('div');
                    label.style.cssText = 'text-align:center; font-size:12px; color:#475569; margin-bottom:6px; min-height:1em;';
                    label.textContent = '0 × 0';
                    picker.appendChild(label);

                    const grid = document.createElement('div');
                    grid.style.cssText = 'display:grid; grid-template-columns:repeat(' + maxCols + ', 20px); gap:2px;';

                    const cells = [];
                    for (let r = 0; r < maxRows; r++) {
                        for (let c = 0; c < maxCols; c++) {
                            const cell = document.createElement('div');
                            cell.style.cssText = 'width:20px; height:20px; border:1px solid rgb(203 213 225); background:#fff; border-radius:2px; cursor:pointer;';
                            cell.dataset.r = r;
                            cell.dataset.c = c;
                            cells.push(cell);
                            grid.appendChild(cell);
                        }
                    }

                    const highlight = (row, col) => {
                        label.textContent = (row + 1) + ' × ' + (col + 1);
                        cells.forEach(cell => {
                            const inside = parseInt(cell.dataset.r, 10) <= row && parseInt(cell.dataset.c, 10) <= col;
                            cell.style.background = inside ? '#1e293b' : '#fff';
                            cell.style.borderColor = inside ? '#1e293b' : 'rgb(203 213 225)';
                        });
                    };

                    grid.addEventListener('mousemove', (ev) => {
                        const target = ev.target.closest('div[data-r]');
                        if (! target) return;
                        highlight(parseInt(target.dataset.r, 10), parseInt(target.dataset.c, 10));
                    });

                    grid.addEventListener('click', (ev) => {
                        const target = ev.target.closest('div[data-r]');
                        if (! target) return;
                        const rows = parseInt(target.dataset.r, 10) + 1;
                        const cols = parseInt(target.dataset.c, 10) + 1;

                        let html = '<table><tbody>';
                        for (let r = 0; r < rows; r++) {
                            html += '<tr>';
                            for (let c = 0; c < cols; c++) html += '<td>&nbsp;</td>';
                            html += '</tr>';
                        }
                        html += '</tbody></table><p><br></p>';

                        const range = editor.getSelection(true) || { index: editor.getLength() };
                        editor.clipboard.dangerouslyPasteHTML(range.index, html, 'user');

                        picker.remove();
                        document.removeEventListener('click', outsideClick);
                    });

                    picker.appendChild(grid);

                    const outsideClick = (ev) => {
                        if (! picker.contains(ev.target) && ev.target !== btn && ! btn.contains(ev.target)) {
                            picker.remove();
                            document.removeEventListener('click', outsideClick);
                        }
                    };

                    document.body.appendChild(picker);
                    // Defer the outside-click listener so the current click doesn't close it.
                    setTimeout(() => document.addEventListener('click', outsideClick), 0);
                };

                // Drop it into the last format group so it sits on the toolbar row.
                const lastGroup = toolbarEl.querySelector('.ql-formats:last-child');
                (lastGroup || toolbarEl).appendChild(btn);
            }
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
        // Shared flatpickr options, reused when wiring up markup that arrives
        // after page load (the lazily-fetched edit-material modal).
        window.FLATPICKR_OPTS = {
            enableTime: true,
            time_24hr: true,
            dateFormat: 'Y-m-d H:i',
            minuteIncrement: 5,
            allowInput: false,
            disableMobile: true,
        };

        /**
         * Backing component for the shared modals on the Materials tab —
         * "Edit material" and "Add Resource".
         *
         * Both bodies are fetched when the modal opens instead of being
         * rendered inline for every material/section. On a 72-material course
         * that inline markup was ~1.2 MB of the page, and each copy's x-init
         * spun up a hidden Quill editor before the user clicked anything.
         *
         * `urlTemplate` uses {id} as the placeholder; it defaults to the
         * edit-material endpoint.
         */
        // The shared resource menu on the materials tab. See the markup in
        // _tab-materials.blade.php for why there is one rather than one per row.
        window.materialMenu = function () {
            return {
                item: null,
                style: '',

                show({ anchor, id, indent, published }) {
                    const r = anchor.getBoundingClientRect();
                    // Under the button, right-aligned to it, kept on screen.
                    this.style = 'top:' + (r.bottom + 4) + 'px; left:' + Math.max(8, r.right - 176) + 'px';
                    this.item = { id, indent, published };
                },

                close() {
                    this.item = null;
                },

                url(kind) {
                    if (! this.item) return '#';
                    const key = 'url' + kind.replace(/(^|-)(\w)/g, (m, sep, c) => c.toUpperCase());
                    // $root, not $el: evaluated from a :action binding, $el is
                    // that form, and the URL templates live on the menu's root.
                    return this.$root.dataset[key].replace('__ID__', this.item.id);
                },
            };
        };

        window.materialEditModal = function () {
            return {
                loading: false,
                failed: false,
                // Why the load was refused, when the server said. Shown in
                // place of the generic "Couldn't load" line.
                reason: '',
                loadedId: null,

                reset() {
                    this.$refs.body.innerHTML = '';
                    this.loadedId = null;
                    this.failed = false;
                    this.reason = '';
                    this.loading = false;
                },

                async reasonFrom(res) {
                    if (!(res.headers.get('content-type') || '').includes('json')) return '';
                    try {
                        return (await res.json()).message || '';
                    } catch (e) {
                        return '';
                    }
                },

                async load(id, urlTemplate = '/materials/{id}/edit-modal') {
                    if (id === null || id === this.loadedId) return;

                    this.loading = true;
                    this.failed = false;
                    this.reason = '';
                    this.$refs.body.innerHTML = '';

                    try {
                        // Accept JSON first: a success still comes back as the
                        // HTML fragment, but a refusal now arrives as JSON with
                        // the policy's message ("not a teacher on this course")
                        // instead of a 403 page the modal can only call "failed".
                        const res = await fetch(urlTemplate.replace('{id}', id), {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json, text/html;q=0.9',
                            },
                        });
                        if (!res.ok) {
                            this.reason = await this.reasonFrom(res);
                            throw new Error(res.status);
                        }

                        this.$refs.body.innerHTML = await res.text();
                        this.loadedId = id;

                        // The fragment carries its own x-data/x-init, so Alpine
                        // has to be told to walk the newly inserted subtree.
                        window.Alpine.initTree(this.$refs.body);

                        // Date pickers don't survive being injected as raw HTML.
                        this.$refs.body
                            .querySelectorAll('[data-flatpickr]')
                            .forEach(el => flatpickr(el, window.FLATPICKR_OPTS));
                    } catch (e) {
                        this.failed = true;
                    } finally {
                        this.loading = false;
                    }
                },
            };
        };

        document.addEventListener('DOMContentLoaded', () => {
            flatpickr('[data-flatpickr]', window.FLATPICKR_OPTS);
            document.querySelectorAll('[data-search-select]').forEach(el => {
                new TomSelect(el, { create: false, allowEmptyOption: true });
            });

            // Drag-and-drop reordering for resource lists inside each section.
            document.querySelectorAll('[data-sortable-materials]').forEach(list => {
                Sortable.create(list, {
                    handle: '.material-drag-handle',
                    animation: 150,
                    ghostClass: 'opacity-40',
                    onEnd: async () => {
                        const sectionId = list.dataset.sectionId;
                        const ids = [...list.querySelectorAll('[data-material-id]')]
                            .map(row => parseInt(row.dataset.materialId, 10));

                        try {
                            const res = await fetch('/sections/' + sectionId + '/materials/reorder', {
                                method: 'PATCH',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                },
                                body: JSON.stringify({ ids }),
                            });
                            if (!res.ok) throw new Error('Save failed (' + res.status + ')');
                        } catch (e) {
                            alert('Could not save new order: ' + e.message);
                        }
                    },
                });
            });
        });
    </script>
