<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentCredentialsExport implements FromArray, WithHeadings
{
    public function __construct(private readonly array $rows) {}

    public function array(): array
    {
        return array_map(fn (array $row) => [
            $row['name'] ?? '',
            $row['username'] ?? '',
            $row['plain_password'] ?? '',
            $row['course'] ?? '',
        ], $this->rows);
    }

    public function headings(): array
    {
        return ['Name', 'Username', 'Password', 'Course'];
    }
}
