@extends('layouts.app')

@section('title', 'Home')

@section('content')
    <div class="space-y-8">
        @if ($notifications->isNotEmpty())
            @php $unreadCount = $notifications->whereNull('read_at')->count(); @endphp
            <section>
                <div class="mb-3 flex items-end justify-between gap-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-600">
                        Announcements
                        @if ($unreadCount > 0)
                            <span class="ml-1 inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-bold text-red-700">
                                {{ $unreadCount }} new
                            </span>
                        @endif
                    </h2>
                    @if ($unreadCount > 0)
                        <form method="POST" action="{{ route('notifications.read-all') }}">
                            @csrf
                            <button type="submit" class="text-xs text-slate-600 hover:underline">Mark all read</button>
                        </form>
                    @endif
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($notifications as $note)
                        @php $isUnread = is_null($note->read_at); @endphp
                        <article class="rounded-lg border bg-white p-4 shadow-sm
                                        {{ $isUnread ? 'border-red-200 ring-1 ring-red-100' : 'border-slate-200' }}">
                            <div class="flex items-baseline justify-between gap-3">
                                <h3 class="text-sm font-semibold text-slate-900">
                                    @if ($isUnread)
                                        <span class="mr-1 inline-block h-2 w-2 rounded-full bg-red-500 align-middle"></span>
                                    @endif
                                    {{ $note->data['title'] ?? 'Announcement' }}
                                </h3>
                                <span class="shrink-0 text-xs text-slate-600">
                                    {{ $note->created_at->format('Y-m-d H:i') }}
                                </span>
                            </div>

                            @if (! empty($note->data['body']))
                                <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $note->data['body'] }}</p>
                            @endif

                            @if ($isUnread)
                                <div class="mt-3 flex justify-end">
                                    <form method="POST" action="{{ route('notifications.read', $note->id) }}">
                                        @csrf
                                        <button type="submit" class="text-xs font-medium text-red-600 hover:underline">
                                            Mark as read
                                        </button>
                                    </form>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($recentCourses->isNotEmpty())
            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-600">Recently accessed</h2>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($recentCourses as $course)
                        <x-course-card :course="$course" />
                    @endforeach
                </div>
            </section>
        @endif

        <section>
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-600">All courses</h2>
            @if ($allCourses->isEmpty())
                <p class="rounded-md border border-slate-200 bg-white p-6 text-sm text-slate-600">
                    You're not enrolled in any courses yet. Please contact your tuition centre administrator.
                </p>
            @else
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    @foreach ($allCourses as $course)
                        <x-course-card :course="$course" />
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endsection
