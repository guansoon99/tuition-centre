<?php

namespace App\Services;

use App\Models\User;
use App\Models\UsernameCounter;
use Illuminate\Support\Facades\DB;

class UsernameGenerator
{
    /**
     * Atomically generate the next available student{N} username.
     * Race-safe via lockForUpdate inside a transaction.
     *
     * The counter marches forward monotonically. If a candidate is already
     * taken (e.g. an admin manually created "student5" via the UI), we skip
     * past it and try the next number until we find one that's free.
     *
     * withTrashed() is essential: users are soft deleted, so a deleted
     * "student5" still holds that username at the DB level (the unique index
     * knows nothing about deleted_at). Without it we'd hand back a name that
     * is already taken and blow up on insert with a unique violation.
     */
    public function generateForStudent(): string
    {
        return DB::transaction(function () {
            $counter = UsernameCounter::lockForUpdate()->find('student');

            if ($counter === null) {
                UsernameCounter::create([
                    'key' => 'student',
                    'last_number' => 0,
                ]);
                $counter = UsernameCounter::lockForUpdate()->find('student');
            }

            do {
                $counter->increment('last_number');
                $candidate = 'student'.$counter->last_number;
            } while (User::withTrashed()->where('username', $candidate)->exists());

            return $candidate;
        });
    }
}
