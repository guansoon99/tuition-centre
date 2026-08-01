<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class EnrollmentImportSampleExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings
{
    public function array(): array
    {
        return [
            ['student1'],
            ['student2'],
            ['student3'],
        ];
    }

    public function headings(): array
    {
        return ['username'];
    }

    /**
     * Force text so Excel doesn't try to interpret usernames like "student001"
     * as numbers and strip leading zeros.
     */
    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT,
        ];
    }
}
