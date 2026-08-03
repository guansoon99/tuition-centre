<?php

namespace App\Http\Controllers\Admin;

use App\Exports\UsersExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
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
        ]);
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

        if ($courseId = $request->integer('course')) {
            $query->where(function ($q) use ($courseId) {
                $q->whereHas('enrollments', fn ($e) => $e->where('course_id', $courseId))
                    ->orWhereHas('taughtCourses', fn ($t) => $t->where('courses.id', $courseId));
            });
        }

        // Enrollment status: 'enrolled' = at least one active enrollment,
        // 'unenrolled' = zero active enrollments. Applies to any user, but
        // only meaningful when combined with role=student (teachers never
        // have enrollments so they always appear as 'unenrolled').
        if ($enrollment = $request->string('enrollment')->value()) {
            if ($enrollment === 'enrolled') {
                $query->whereHas('enrollments', fn ($e) => $e->where('is_active', true));
            } elseif ($enrollment === 'unenrolled') {
                $query->whereDoesntHave('enrollments', fn ($e) => $e->where('is_active', true));
            }
        }

        return $query;
    }

    public function show(User $user): View
    {
        return view('admin.users.show', ['user' => $user->load('roles')]);
    }

    public function create(): View
    {
        return view('admin.users.create');
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
        return view('admin.users.edit', ['user' => $user]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        $user->fill([
            'username' => $data['username'],
            'name' => $data['name'],
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
