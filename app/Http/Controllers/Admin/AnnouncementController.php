<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AnnouncementRequest;
use App\Models\Course;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        // Group all delivered notification rows by their announcement_id so each
        // logical announcement shows as one item in the admin list.
        $rows = DatabaseNotification::query()
            ->where('type', AdminAnnouncementNotification::class)
            ->orderByDesc('created_at')
            ->get();

        $announcements = $rows
            ->groupBy(fn ($n) => $n->data['announcement_id'] ?? $n->id)
            ->map(function ($group) {
                $first = $group->first();
                return (object) [
                    'id' => $first->data['announcement_id'] ?? $first->id,
                    'title' => $first->data['title'] ?? '',
                    'body' => $first->data['body'] ?? '',
                    'audience_label' => $first->data['audience_label'] ?? '',
                    'starts_at' => $first->data['starts_at'] ?? null,
                    'ends_at' => $first->data['ends_at'] ?? null,
                    'sent_at' => $first->created_at,
                    'recipients' => $group->count(),
                    'read' => $group->whereNotNull('read_at')->count(),
                ];
            })
            ->values();

        return view('admin.announcements.index', [
            'announcements' => $announcements,
        ]);
    }

    public function create(): View
    {
        return view('admin.announcements.create', [
            'courses' => $this->coursesForSelect(),
        ]);
    }

    public function store(AnnouncementRequest $request): RedirectResponse
    {
        $audience = $request->input('audience');
        $courseId = $request->integer('course_id') ?: null;
        $course = $courseId ? Course::find($courseId) : null;

        $audienceUsers = $this->resolveAudience($audience, $course);

        if ($audienceUsers->isEmpty()) {
            return back()
                ->withInput()
                ->withErrors(['audience' => 'No active users match this audience.']);
        }

        // Admins always receive a copy so they have full visibility of every
        // announcement that's been sent.
        $recipients = $audienceUsers->merge($this->activeAdmins())->unique('id')->values();

        Notification::send(
            $recipients,
            new AdminAnnouncementNotification(
                title: $request->input('title'),
                body: $request->input('body'),
                audienceLabel: $this->buildAudienceLabel($audience, $course),
                startsAt: Carbon::createFromFormat('Y-m-d H:i', $request->input('starts_at'))->format('Y-m-d H:i:s'),
                endsAt: Carbon::createFromFormat('Y-m-d H:i', $request->input('ends_at'))->format('Y-m-d H:i:s'),
            )
        );

        return redirect()
            ->route('announcements.index')
            ->with('status', "Announcement sent to {$recipients->count()} user(s).");
    }

    public function show(string $id): View
    {
        return view('admin.announcements.show', [
            'announcement' => $this->buildAnnouncementObject($id),
        ]);
    }

    public function edit(string $id): View
    {
        return view('admin.announcements.edit', [
            'announcement' => $this->buildAnnouncementObject($id),
        ]);
    }

    private function buildAnnouncementObject(string $id): object
    {
        $rows = $this->notificationsFor($id);
        abort_if($rows->isEmpty(), 404);

        $sample = $rows->first();

        return (object) [
            'id' => $id,
            'title' => $sample->data['title'] ?? '',
            'body' => $sample->data['body'] ?? '',
            'starts_at' => $sample->data['starts_at']
                ? Carbon::parse($sample->data['starts_at'])->format('Y-m-d H:i')
                : '',
            'ends_at' => $sample->data['ends_at']
                ? Carbon::parse($sample->data['ends_at'])->format('Y-m-d H:i')
                : '',
            'audience_label' => $sample->data['audience_label'] ?? '',
            'sent_at' => $sample->created_at,
            'recipients' => $rows->count(),
            'read' => $rows->whereNotNull('read_at')->count(),
        ];
    }

    public function update(AnnouncementRequest $request, string $id): RedirectResponse
    {
        $matching = $this->notificationsFor($id);

        abort_if($matching->isEmpty(), 404);

        $newStartsAt = Carbon::createFromFormat('Y-m-d H:i', $request->input('starts_at'))->format('Y-m-d H:i:s');
        $newEndsAt = Carbon::createFromFormat('Y-m-d H:i', $request->input('ends_at'))->format('Y-m-d H:i:s');

        foreach ($matching as $row) {
            $data = $row->data;
            $data['title'] = $request->input('title');
            $data['body'] = $request->input('body');
            $data['starts_at'] = $newStartsAt;
            $data['ends_at'] = $newEndsAt;
            $row->data = $data;
            $row->save();
        }

        return redirect()
            ->route('announcements.index')
            ->with('status', 'Announcement updated.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $matching = $this->notificationsFor($id);
        $count = $matching->count();

        DatabaseNotification::whereIn('id', $matching->pluck('id'))->delete();

        return redirect()
            ->route('announcements.index')
            ->with('status', "Announcement deleted ({$count} recipient row(s) removed).");
    }

    private function firstNotificationFor(string $id): ?DatabaseNotification
    {
        return $this->notificationsFor($id)->first();
    }

    /**
     * Find all DatabaseNotification rows whose JSON data has announcement_id == $id.
     * Filtering happens in PHP so it works the same on SQLite, MySQL, and PostgreSQL.
     */
    private function notificationsFor(string $id)
    {
        return DatabaseNotification::query()
            ->where('type', AdminAnnouncementNotification::class)
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn ($n) => ($n->data['announcement_id'] ?? null) === $id)
            ->values();
    }

    private function activeAdmins()
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->get();
    }

    private function resolveAudience(string $audience, ?Course $course)
    {
        $query = User::query()->where('is_active', true);

        if ($audience === 'students') {
            $query->whereHas('roles', fn ($q) => $q->where('name', 'student'));
            if ($course) {
                $query->whereHas('enrollments', fn ($q) => $q
                    ->where('course_id', $course->id)
                    ->where('is_active', true));
            }
        } elseif ($audience === 'teachers') {
            $query->whereHas('taughtCourses', function ($q) use ($course) {
                if ($course) {
                    $q->where('courses.id', $course->id);
                }
            });
        } elseif ($course) {
            $query->where(function ($q) use ($course) {
                $q->whereHas('enrollments', fn ($qq) => $qq
                    ->where('course_id', $course->id)
                    ->where('is_active', true))
                  ->orWhereHas('taughtCourses', fn ($qq) => $qq->where('courses.id', $course->id));
            });
        }

        return $query->get();
    }

    private function buildAudienceLabel(string $audience, ?Course $course): string
    {
        $role = match ($audience) {
            'students' => 'Students',
            'teachers' => 'Teachers',
            default => 'Everyone',
        };

        return $course
            ? "{$role} of {$course->code}"
            : ($audience === 'all' ? 'Everyone' : "All {$role}");
    }

    private function coursesForSelect()
    {
        return Course::where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }
}
