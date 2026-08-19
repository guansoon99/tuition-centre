{{--
    An assignment's title, as a bare heading.

    Shared so the student and teacher views cannot drift: the teacher's used to
    be a smaller heading inside a white card, which made the same assignment
    look like a different page depending on who opened it.

    Matches the "open as separate page" view's heading — no card of its own.

    Expects: $material
--}}
<h1 class="text-2xl font-semibold text-slate-900">
    {{ $material->title ?: 'Assignment' }}
</h1>
