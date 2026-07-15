<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentCredentialsExport implements FromArray, ShouldAutoSize, WithHeadings
{
    public function __construct(
        private readonly array $okRows,
        private readonly array $skippedRows = [],
    ) {}

    public function array(): array
    {
        $ok = array_map(fn (array $row) => [
            $row['name'] ?? '',
            $row['username'] ?? '',
            $row['plain_password'] ?? '',
            $row['course'] ?? '',
            'Created',
            '',
        ], $this->okRows);

        $skipped = array_map(fn (array $row) => [
            $row['name'] ?? '',
            '',
            '',
            $row['course'] ?? '',
            'Skipped',
            $row['reason'] ?? '',
        ], $this->skippedRows);

        return array_merge($ok, $skipped);
    }

    public function headings(): array
    {
        return ['Name', 'Username', 'Password', 'Course', 'Status', 'Reason'];
    }
}
