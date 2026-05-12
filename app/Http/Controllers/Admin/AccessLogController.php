<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AccessLogController extends Controller
{
    public function index(Request $request): View
    {
        $query = AccessLog::query()->with(['user:id,username,name', 'material:id,title']);

        if ($action = $request->string('action')->value()) {
            $query->where('action', $action);
        }

        if ($username = $request->string('q')->trim()->value()) {
            $query->whereHas('user', fn ($q) => $q
                ->where('username', 'like', "%{$username}%")
                ->orWhere('name', 'like', "%{$username}%"));
        }

        $logs = $query->orderByDesc('accessed_at')->paginate(50)->withQueryString();

        return view('admin.access-logs.index', [
            'logs' => $logs,
            'filters' => $request->only(['action', 'q']),
        ]);
    }
}
