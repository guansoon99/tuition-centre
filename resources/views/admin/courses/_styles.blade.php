    @vite('resources/js/flatpickr.js')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css">
    <style>
        /* Toolbar wraps to the next row when it doesn't fit — no scrollbar,
           no vertical gap between wrapped rows. */
        /* One editor height for every material type.
           On .ql-editor, not the wrapper: Quill mounts its toolbar and editor
           inside the container, so a height on the outer div leaves the typing
           area its default size. Hand-written rather than an arbitrary-value utility
           because those arbitrary values are absent from the committed CSS
           build and silently do nothing. */
        .ql-editor { min-height: 280px; }
        .ql-toolbar.ql-snow { line-height: 0; padding: 4px 6px; }
        .ql-toolbar.ql-snow .ql-formats { display: inline-flex; flex-wrap: wrap; align-items: center; vertical-align: middle; margin: 0 8px 0 0; row-gap: 4px; }
        .ql-editor table { border-collapse: collapse; margin: 0.75rem 0; width: 100%; }
        .ql-editor th, .ql-editor td { border: 1px solid #000; padding: 0.375rem 0.5rem; text-align: left; vertical-align: top; }
        .ql-editor th { background: rgb(241 245 249); font-weight: 600; }

        /* Link tooltip — pin to top of editor and restore input styling that
           Tailwind's preflight strips. Without this the popup lands near the
           cursor (can fall outside the modal) and the input reads as a black bar. */
        .ql-snow .ql-tooltip {
            left: 12px !important;
            top: 8px !important;
            transform: none !important;
            z-index: 50;
            background: #fff;
            border: 1px solid rgb(203 213 225);
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            padding: 6px 10px;
            font-size: 13px;
            color: rgb(51 65 85);
        }
        .ql-snow .ql-tooltip input[type=text] {
            display: inline-block;
            width: 220px;
            padding: 4px 8px;
            border: 1px solid rgb(203 213 225);
            border-radius: 4px;
            background: #fff;
            color: rgb(15 23 42);
            font-size: 13px;
            margin: 0 6px;
        }
        .ql-snow .ql-tooltip a { color: rgb(37 99 235); padding: 0 6px; cursor: pointer; font-weight: 500; }

        /* Header picker dropdown — restore line-height that our toolbar's
           `line-height: 0` (used for wrapping) strips out. Scoped to
           text-based pickers (:not(.ql-icon-picker)) so we don't stomp
           on icon pickers like Align, which need Quill's default 24×24
           dimensions for their SVG icons to render. */
        .ql-snow .ql-picker:not(.ql-icon-picker) .ql-picker-options {
            line-height: 1.4;
            padding: 4px 0;
            background: #fff;
            border: 1px solid rgb(203 213 225);
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .ql-snow .ql-picker:not(.ql-icon-picker) .ql-picker-options .ql-picker-item {
            display: block;
            padding: 4px 12px;
            line-height: 1.4;
            cursor: pointer;
        }
        .ql-snow .ql-picker:not(.ql-icon-picker) .ql-picker-options .ql-picker-item:hover { color: rgb(37 99 235); }
    </style>
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
        .ts-wrapper { padding: 0 !important; border: 0 !important; box-shadow: none !important; background: transparent !important; }
        .ts-wrapper.single .ts-control,
        .ts-wrapper.single.input-active .ts-control {
            border: 1px solid rgb(203 213 225) !important;
            border-radius: 0.375rem;
            padding: 0.375rem 2rem 0.375rem 0.75rem;
            min-height: 2.25rem;
            font-size: 0.875rem;
            background: #fff;
            box-shadow: none;
            /* Native-select-style chevron */
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%2364748b' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 8px center;
            background-size: 18px;
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
