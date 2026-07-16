@props(['user' => null, 'action', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif

    @php
        $currentRole = old('role', $user?->roles->first()?->name ?? 'student');
        $allRoles = \Spatie\Permission\Models\Role::where('name', '!=', 'admin')->orderBy('name')->pluck('name');
        // Only admins can change a user's role. Others see the pills read-only.
        $canAssignRole = auth()->user()?->hasRole('admin') ?? false;
    @endphp

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">
            Role
            @unless ($canAssignRole)
                <span class="ml-1 text-xs font-normal text-slate-500">(admin only)</span>
            @endunless
        </label>
        <div class="flex flex-wrap gap-2">
            @foreach ($allRoles as $r)
                <label class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm has-[:checked]:border-slate-900 has-[:checked]:bg-slate-900 has-[:checked]:text-white
                              {{ $canAssignRole ? 'cursor-pointer hover:bg-slate-50' : 'cursor-not-allowed opacity-70' }}">
                    <input type="radio" name="role" value="{{ $r }}"
                           @if ($canAssignRole) required @endif
                           @checked($currentRole === $r)
                           @unless ($canAssignRole) disabled @endunless
                           class="h-4 w-4 accent-slate-900">
                    {{ ucfirst($r) }}
                </label>
            @endforeach
        </div>
        {{-- Disabled radios don't submit — carry the existing role in a
             hidden field so the required-role validation still passes. --}}
        @unless ($canAssignRole)
            <input type="hidden" name="role" value="{{ $currentRole }}">
        @endunless
        @error('role') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Username</label>
            <input type="text" name="username" required value="{{ old('username', $user?->username) }}"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            @error('username') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Name</label>
            <input type="text" name="name" required value="{{ old('name', $user?->name) }}"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Phone (optional)</label>
            <input type="text" name="phone" value="{{ old('phone', $user?->phone) }}"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">IC Number (optional)</label>
            <input type="text" name="ic_number" value="{{ old('ic_number', $user?->ic_number) }}"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            @error('ic_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2">
            <label class="mb-1 block text-sm font-medium text-slate-700">Candidate Number (optional)</label>
            <input type="text" name="candidate_number" value="{{ old('candidate_number', $user?->candidate_number) }}"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            @error('candidate_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <fieldset class="rounded-md border border-slate-200 p-4">
        <legend class="px-2 text-sm font-medium">Password</legend>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs text-slate-600">{{ $user ? 'New password' : 'Password' }}</label>
                <input type="password" name="password"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-slate-600">Confirm</label>
                <input type="password" name="password_confirmation"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </div>
        </div>
        @if ($user)
            <p class="mt-2 text-xs text-slate-500">Leave blank to keep current password.</p>
        @endif
    </fieldset>

    <div class="flex gap-3">
        <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
            {{ $user ? 'Save' : 'Create user' }}
        </button>
        <a href="{{ route('users.index') }}"
           class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
            Cancel
        </a>
    </div>
</form>
