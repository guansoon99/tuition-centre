@php
    $user = auth()->user();
    $isAdmin = $user?->hasRole('admin');
    $isStudent = $user?->hasRole('student');

    // Each menu item is shown if the user has the matching permission. Admin
    // auto-passes everything via Gate::before in AuthServiceProvider.
    $sections = [];

    // Top-level home (no group header).
    $sections[] = ['items' => [
        ['label' => 'Home', 'route' => 'home', 'active' => request()->routeIs('home')],
    ]];

    if ($user?->can('courses.view')) {
        $sections[0]['items'][] = ['label' => 'Courses', 'route' => 'courses.index', 'active' => request()->routeIs('courses.*')];
    }

    // "Users" section — show whichever items the user has permission for.
    $usersItems = [];
    if ($user?->can('users.view')) {
        $usersItems[] = ['label' => 'Users', 'route' => 'users.index', 'active' => request()->routeIs('users.*')];
    }
    if ($user?->can('users.import')) {
        $usersItems[] = ['label' => 'Import', 'route' => 'import.show', 'active' => request()->routeIs('import.*')];
    }
    if ($user?->can('roles.view')) {
        $usersItems[] = ['label' => 'Roles', 'route' => 'roles.index', 'active' => request()->routeIs('roles.*')];
    }
    if (! empty($usersItems)) {
        $sections[] = ['title' => 'Users', 'items' => $usersItems];
    }

    // "Settings" section — visibility gated by the .view perm of each area.
    $settingsItems = [];
    if ($user?->can('banner.view')) {
        $settingsItems[] = ['label' => 'Banner', 'route' => 'banner.index', 'active' => request()->routeIs('banner.*')];
    }
    if ($user?->can('announcements.view')) {
        $settingsItems[] = ['label' => 'Announcement', 'route' => 'announcements.index', 'active' => request()->routeIs('announcements.*')];
    }
    if ($user?->can('settings.view')) {
        $settingsItems[] = ['label' => 'Website Settings', 'route' => 'settings.show', 'active' => request()->routeIs('settings.*')];
    }
    if (! empty($settingsItems)) {
        $sections[] = ['title' => 'Settings', 'items' => $settingsItems];
    }

    $accountActive = request()->routeIs('account.*');
@endphp

{{-- Mobile overlay backdrop --}}
<div x-show="sidebarOpen"
     x-transition.opacity
     @click="sidebarOpen = false"
     x-cloak
     class="fixed inset-0 z-20 bg-black/40 lg:hidden"></div>

<aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
       class="fixed inset-y-0 left-0 top-14 z-30 w-60 transform border-r border-slate-200 bg-white transition-transform duration-200 lg:sticky lg:top-14 lg:h-[calc(100vh-3.5rem)] lg:translate-x-0">
    <div class="flex h-full flex-col">
        <nav class="flex flex-1 flex-col overflow-y-auto p-3">
            @foreach ($sections as $sectionIndex => $section)
                @php $hasTitle = isset($section['title']); @endphp
                <div class="{{ $sectionIndex > 0 ? ($hasTitle ? 'mt-5 border-t border-slate-300 pt-4' : 'mt-5') : '' }}">
                    @if ($hasTitle)
                        <p class="mb-2 px-3 text-[11px] font-bold uppercase tracking-[0.15em] text-slate-500">
                            {{ $section['title'] }}
                        </p>
                    @endif

                    <div class="flex flex-col gap-1">
                        @foreach ($section['items'] as $item)
                            <a href="{{ route($item['route']) }}"
                               @click="sidebarOpen = false"
                               class="flex items-center rounded-md px-3 py-2 text-sm font-medium
                                      {{ $item['active']
                                         ? 'bg-slate-900 text-white'
                                         : 'text-slate-900 hover:bg-slate-100' }}">
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>

        <div class="space-y-1 border-t border-slate-300 p-3">
            <a href="{{ route('account.show') }}"
               @click="sidebarOpen = false"
               class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm font-medium
                      {{ $accountActive
                         ? 'bg-slate-900 text-white'
                         : 'text-slate-900 hover:bg-slate-100' }}">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                Account
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    Sign out
                </button>
            </form>
        </div>
    </div>
</aside>
