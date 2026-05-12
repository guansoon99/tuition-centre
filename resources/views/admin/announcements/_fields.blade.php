@props([
    'mode' => 'create',  // 'create' or 'edit'
    'announcement' => null,
    'courses' => collect(),
])

<div>
    <label class="mb-1 block text-sm font-medium text-slate-700">Title</label>
    <input type="text" name="title" required maxlength="120"
           value="{{ old('title', $announcement?->title) }}"
           class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
    @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
</div>

<div>
    <label class="mb-1 block text-sm font-medium text-slate-700">Message</label>
    <textarea name="body" required maxlength="2000" rows="4"
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">{{ old('body', $announcement?->body) }}</textarea>
    @error('body') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
</div>

@if ($mode === 'create')
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Audience</label>
            <select name="audience" required class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option value="students" @selected(old('audience', 'students') === 'students')>Students</option>
                <option value="teachers" @selected(old('audience') === 'teachers')>Teachers</option>
                <option value="all" @selected(old('audience') === 'all')>Everyone</option>
            </select>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Course</label>
            <select name="course_id" data-search-select
                    class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option value="">All</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected((int) old('course_id') === $course->id)>
                        {{ $course->code }} — {{ \Illuminate\Support\Str::limit($course->name, 40) }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>
@else
    @if ($announcement?->audience_label)
        <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
            Audience: <strong>{{ $announcement->audience_label }}</strong>
        </div>
    @endif
@endif

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Start</label>
        <input type="text" name="starts_at" required readonly
               placeholder="2026-05-21 09:00"
               value="{{ old('starts_at', $announcement?->starts_at) }}"
               data-flatpickr
               class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono" />
        @error('starts_at') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">End</label>
        <input type="text" name="ends_at" required readonly
               placeholder="2026-05-21 17:00"
               value="{{ old('ends_at', $announcement?->ends_at) }}"
               data-flatpickr
               class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono" />
        @error('ends_at') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
</div>
