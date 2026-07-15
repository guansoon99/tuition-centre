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
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'filters' => $request->only(['q', 'role', 'active', 'course']),
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
        $role = $data['role'];

        $user->fill([
            'username' => $data['username'],
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'ic_number' => $data['ic_number'] ?? null,
            'candidate_number' => $data['candidate_number'] ?? null,
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();
        $user->syncRoles([$role]);

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
