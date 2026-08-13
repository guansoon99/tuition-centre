    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
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
                            video: function () {
                                const input = document.createElement('input');
                                input.type = 'file';
                                input.accept = 'video/mp4,video/webm,video/quicktime';
                                input.click();

                                input.onchange = async () => {
                                    const file = input.files[0];
                                    if (!file) return;

                                    const form = new FormData();
                                    form.append('video', file);

                                    try {
                                        const res = await fetch('{{ route('sections.upload-video') }}', {
                                            method: 'POST',
                                            headers: {
                                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                                'Accept': 'application/json',
                                            },
                                            body: form,
                                        });
                                        if (!res.ok) throw new Error('Upload failed (' + res.status + ')');
                                        const data = await res.json();

                                        // Insert an HTML5 <video> tag at the cursor. Quill's built-in
                                        // video embed uses <iframe> (for YouTube-style URLs) — we want
                                        // native playback for uploaded files.
                                        const range = editor.getSelection(true);
                                        const html = '<p><video controls src="' + data.url + '" style="max-width:100%;"></video></p>';
                                        editor.clipboard.dangerouslyPasteHTML(range.index, html, 'user');
                                    } catch (e) {
                                        alert('Video upload failed: ' + e.message);
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
        document.addEventListener('DOMContentLoaded', () => {
            flatpickr('[data-flatpickr]', {
                enableTime: true,
                time_24hr: true,
                dateFormat: 'Y-m-d H:i',
                minuteIncrement: 5,
                allowInput: false,
                disableMobile: true,
            });
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
