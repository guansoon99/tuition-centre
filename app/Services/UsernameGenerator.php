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
            } while (User::where('username', $candidate)->exists());

            return $candidate;
        });
    }
}
