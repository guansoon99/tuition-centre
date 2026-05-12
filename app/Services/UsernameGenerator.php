<?php

namespace App\Services;

use App\Models\UsernameCounter;
use Illuminate\Support\Facades\DB;

class UsernameGenerator
{
    /**
     * Atomically generate the next student{N} username.
     * Race-safe via lockForUpdate inside a transaction.
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

            $counter->increment('last_number');

            return 'student'.$counter->last_number;
        });
    }
}
