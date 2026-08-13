        <section x-show="tab === 'details'" x-cloak>
            @include('admin.courses._form', [
                'course' => $course,
                'action' => route('courses.update', $course),
                'method' => 'PATCH',
            ])
        </section>
