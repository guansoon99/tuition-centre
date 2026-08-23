<?php

namespace App\Http\Controllers\Admin;

use App\Exports\UsersExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\Course;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = $this->buildIndexQuery($request)
            ->with('roles')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'filters' => $request->only(['q', 'role', 'active', 'course', 'enrollment']),
            // Filter dropdown options. Passed in rather than queried from the
            // Blade so every query this page runs is visible in one place.
            'roleOptions' => $this->assignableRoles(),
            'courseOptions' => Course::orderByDesc('created_at')->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * Roles an admin can hand out. `admin` is excluded — it's granted by
     * seeding/tinker, never through the user form.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function assignableRoles()
    {
        return Role::where('name', '!=', 'admin')->orderBy('name')->pluck('name');
    }

    public function export(Request $request): BinaryFileResponse
    {
        $filename = 'users_'.now()->format('Y-m-d_His').'.xlsx';

        return Excel::download(new UsersExport($this->buildIndexQuery($request)), $filename);
    }

    private function buildIndexQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = User::query()
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'admin'));

        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($role = $request->string('role')->value()) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Course + Enrollment filters. Uses courseMemberships (all roles)
        // so teacher assignments count as being "linked" to a course.
        //
        // The Course dropdown means "users linked to this course" and the
        // Enrollment dropdown slices that scope by row state — Enrolled =
        // active row, Unenrolled = inactive/expired row. This keeps the
        // union invariant: All Enrollment = Enrolled ∪ Unenrolled.
        //
        //   Enrolled (any course)       → users with ≥1 active membership
        //   Unenrolled (any course)     → users with 0 active memberships
        //   Course=X + Enrolled         → users active in X
        //   Course=X + Unenrolled       → users with an inactive row for X
        //                                (ex-members of the course)
        //   Course=X + All Enrollment   → users linked to X in any state
        $courseId = $request->integer('course') ?: null;
        $enrollment = $request->string('enrollment')->value() ?: null;

        if ($courseId && $enrollment === 'enrolled') {
            $query->whereHas('courseMemberships', function ($q) use ($courseId) {
                $q->where('course_id', $courseId)->where('is_active', true);
            });
        } elseif ($courseId && $enrollment === 'unenrolled') {
            $query->whereHas('courseMemberships', function ($q) use ($courseId) {
                $q->where('course_id', $courseId)->where('is_active', false);
            });
        } elseif ($courseId) {
            $query->whereHas('courseMemberships', fn ($q) => $q->where('course_id', $courseId));
        } elseif ($enrollment === 'enrolled') {
            $query->whereHas('courseMemberships', fn ($e) => $e->where('is_active', true));
        } elseif ($enrollment === 'unenrolled') {
            $query->whereDoesntHave('courseMemberships', fn ($e) => $e->where('is_active', true));
        }

        return $query;
    }

    /**
     * Bulk delete — SOFT deletes the selected users. Safety rails:
     *   - Cannot delete the current user (would lock them out).
     *   - Cannot delete any admin (protects the admin realm from a bulk
     *     accident by a role holder who happens to have users.delete).
     *
     * Soft delete means the row survives with deleted_at set, so:
     *   - the user vanishes from every Eloquent query and can no longer log
     *     in (Laravel's auth provider applies the global scope), but
     *   - their enrollments, access logs, submissions and grades are all
     *     preserved, and the account can be restored.
     *
     * Nothing cascades, precisely because nothing is really deleted — a soft
     * delete is an UPDATE, and ON DELETE CASCADE only fires on a DELETE.
     * That's the intent here: the history is the point.
     *
     * Skipped IDs are silently ignored — no flash message is set.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('users.delete'), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:users,id'],
        ]);

        $selfId = $request->user()->id;
        $candidates = User::with('roles')->whereIn('id', $data['ids'])->get();

        foreach ($candidates as $user) {
            if ($user->id === $selfId || $user->hasRole('admin')) {
                continue;
            }
            $user->delete();
        }

        // Return to the exact filtered URL the admin was on — Laravel's
        // back() uses the session's previous URL, which is the /users page
        // (with its ?q=&role=&… query string) that submitted this POST.
        return redirect()->back(fallback: route('users.index'));
    }

    public function show(User $user): View
    {
        return view('admin.users.show', ['user' => $user->load('roles')]);
    }

    public function create(): View
    {
        return view('admin.users.create', ['roleOptions' => $this->assignableRoles()]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $role = $data['role'];
        $password = $data['password'];

        unset($data['role'], $data['password'], $data['password_confirmation']);

        $user = User::create([
            ...$data,
            'is_active' => true,
            'password' => $password,
            // Track plaintext only for students so the admin can re-hand out
            // credentials. See UsersExport.
            'plain_password' => $role === 'student' ? $password : null,
        ]);
        $user->assignRole($role);

        return redirect()
            ->route('users.index')
            ->with('status', "User {$user->username} created.");
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', [
            'user' => $user,
            'roleOptions' => $this->assignableRoles(),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        $user->fill([
            'username' => $data['username'],
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'ic_number' => $data['ic_number'] ?? null,
            'candidate_number' => $data['candidate_number'] ?? null,
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
            // Only track plaintext for student users. If the user is a
            // student (post-role-sync below) and admin set a new password,
            // we'll set it after syncRoles.
        }

        $user->save();

        // Role changes are admin-only. Non-admins who craft a POST with a
        // different role are silently ignored — we keep the existing one.
        if ($request->user()?->hasRole('admin')) {
            $user->syncRoles([$data['role']]);
        }

        // After roles are synced: keep plain_password aligned. Track for
        // students on any admin-triggered password change; clear if the
        // user is no longer a student.
        $isStudentNow = $user->hasRole('student');
        if (! $isStudentNow && $user->plain_password !== null) {
            $user->plain_password = null;
            $user->save();
        } elseif ($isStudentNow && ! empty($data['password'])) {
            $user->plain_password = $data['password'];
            $user->save();
        }

        return redirect()
            ->route('users.index')
            ->with('status', "User {$user->username} updated.");
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['user' => 'You cannot deactivate your own account.']);
        }

        $user->update(['is_active' => false]);

        return redirect()
            ->route('users.index')
            ->with('status', "User {$user->username} deactivated.");
    }

    public function activate(User $user): RedirectResponse
    {
        $user->update(['is_active' => true]);

        return redirect()
            ->route('users.index')
            ->with('status', "User {$user->username} activated.");
    }
}
