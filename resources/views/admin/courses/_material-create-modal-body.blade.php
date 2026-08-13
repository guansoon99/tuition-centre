{{--
    Body of the "Add Resource" modal on the course Materials tab.

    Served on demand by Teacher\MaterialController::createModal rather than
    rendered once per section. teacher.materials._form is ~330 lines and its
    @push blocks are not @once-guarded, so a 12-section course was emitting a
    dozen copies of the same Quill styles and scripts into the page.

    Rendered without a layout, so the @push blocks inside _form go nowhere —
    which is fine: the parent page already loads Quill, flatpickr and defines
    window.initQuillEditor.

    Expects: $section
--}}
<div class="mb-4 flex items-center justify-between">
    <h3 class="text-lg font-semibold text-slate-900">Add Resource</h3>
    <button type="button" @click="openNewMaterialFor = null"
            class="text-slate-400 hover:text-slate-600">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
    </button>
</div>

@include('teacher.materials._form', [
    'action' => route('materials.store', $section),
    'material' => null,
    'course' => $section->course,
])
