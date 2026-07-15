@props(['result', 'title'])

<div class="space-y-3">
    <h2 class="text-base font-semibold text-slate-900">{{ $title }}</h2>

    <div class="grid grid-cols-3 gap-3">
        <div class="rounded-md bg-emerald-50 p-3">
            <p class="text-xs uppercase text-emerald-700">OK</p>
            <p class="text-2xl font-semibold text-emerald-900">{{ count($result['ok'] ?? []) }}</p>
        </div>
        <div class="rounded-md bg-amber-50 p-3">
            <p class="text-xs uppercase text-amber-700">{{ \Illuminate\Support\Str::plural('Duplicate', count($result['skipped'] ?? [])) }}</p>
            <p class="text-2xl font-semibold text-amber-900">{{ count($result['skipped'] ?? []) }}</p>
        </div>
        <div class="rounded-md bg-red-50 p-3">
            <p class="text-xs uppercase text-red-700">Errors</p>
            <p class="text-2xl font-semibold text-red-900">{{ count($result['errors'] ?? []) }}</p>
        </div>
    </div>

    @if (! empty($result['errors']))
        <div class="overflow-hidden rounded-md border border-red-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-red-50 text-left text-xs uppercase text-red-700">
                    <tr><th class="px-3 py-2">Line</th><th class="px-3 py-2">Name</th><th class="px-3 py-2">Reason</th></tr>
                </thead>
                <tbody class="divide-y divide-red-100">
                    @foreach ($result['errors'] as $err)
                        <tr>
                            <td class="px-3 py-2 font-mono text-xs">{{ $err['line'] }}</td>
                            <td class="px-3 py-2">{{ $err['name'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-red-800">{{ $err['reason'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (! empty($result['skipped']))
        <div class="overflow-hidden rounded-md border border-amber-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-amber-50 text-left text-xs uppercase text-amber-700">
                    <tr><th class="px-3 py-2">Line</th><th class="px-3 py-2">Name</th><th class="px-3 py-2">Course</th><th class="px-3 py-2">Reason</th></tr>
                </thead>
                <tbody class="divide-y divide-amber-100">
                    @foreach ($result['skipped'] as $sk)
                        <tr>
                            <td class="px-3 py-2 font-mono text-xs">{{ $sk['line'] }}</td>
                            <td class="px-3 py-2">{{ $sk['name'] }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $sk['course'] ?? '' }}</td>
                            <td class="px-3 py-2">{{ $sk['reason'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (! empty($result['ok']) && isset($result['ok'][0]['username']))
        <div class="overflow-hidden rounded-md border border-emerald-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-emerald-50 text-left text-xs uppercase text-emerald-700">
                    <tr><th class="px-3 py-2">Username</th><th class="px-3 py-2">Name</th><th class="px-3 py-2">Course</th><th class="px-3 py-2">Password</th></tr>
                </thead>
                <tbody class="divide-y divide-emerald-100">
                    @foreach ($result['ok'] as $row)
                        <tr>
                            <td class="px-3 py-2 font-mono text-xs">{{ $row['username'] ?? '' }}</td>
                            <td class="px-3 py-2">{{ $row['name'] }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $row['course'] ?? '' }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $row['plain_password'] ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
