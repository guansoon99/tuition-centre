<?php

namespace App\Services;

/**
 * The password a student gets from the batch import: six characters, three
 * lowercase letters followed by three digits, e.g. "abd123".
 *
 * The fixed shape is what keeps it readable on paper: a character in the
 * first half is always a letter and one in the second half always a digit,
 * so "l" and "1", or "o" and "0", can never be mistaken for each other.
 */
class PasswordGenerator
{
    private const LETTERS = 'abcdefghijklmnopqrstuvwxyz';

    private const DIGITS = '0123456789';

    public const LETTER_COUNT = 3;

    public const DIGIT_COUNT = 3;

    public function generate(): string
    {
        $password = '';

        for ($i = 0; $i < self::LETTER_COUNT; $i++) {
            $password .= self::LETTERS[random_int(0, strlen(self::LETTERS) - 1)];
        }

        for ($i = 0; $i < self::DIGIT_COUNT; $i++) {
            $password .= self::DIGITS[random_int(0, strlen(self::DIGITS) - 1)];
        }

        return $password;
    }
}
