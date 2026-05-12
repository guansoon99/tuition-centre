<?php

namespace App\Services;

class PasswordGenerator
{
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // no I, O
    private const LOWER = 'abcdefghijkmnopqrstuvwxyz'; // no l
    private const DIGIT = '23456789'; // no 0, 1

    public function generate(int $length = 10): string
    {
        if ($length < 4) {
            $length = 4;
        }

        $all = self::UPPER.self::LOWER.self::DIGIT;

        $chars = [
            self::UPPER[random_int(0, strlen(self::UPPER) - 1)],
            self::LOWER[random_int(0, strlen(self::LOWER) - 1)],
            self::DIGIT[random_int(0, strlen(self::DIGIT) - 1)],
        ];

        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        shuffle($chars);

        return implode('', $chars);
    }
}
