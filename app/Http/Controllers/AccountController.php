<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Models\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function show(): View
    {
        return view('account.show');
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasRole('student') && ! SiteSettings::current()->students_can_change_password) {
            abort(403, 'Password changes are disabled for students.');
        }

        // Self-service password change. Also update plain_password for
        // student users so the admin always has the current value on hand
        // (avoids a reset cycle when a student forgets it).
        $user->forceFill([
            'password' => $request->input('password'),
            'plain_password' => $user->hasRole('student') ? $request->input('password') : null,
        ])->save();

        return redirect()->route('account.show')->with('status', 'Password updated.');
    }
}
