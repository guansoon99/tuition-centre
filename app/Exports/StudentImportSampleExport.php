<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class StudentImportSampleExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings
{
    public function __construct(private readonly ?string $sampleCourseCode = null) {}

    public function array(): array
    {
        $course = $this->sampleCourseCode ?? 'STPM01';

        return [
            ['Ali Bin Ahmad', '0123456789', '050101011234', 'BA001A001', $course, '2027-01-31'],
            ['Bee Chen Lim', '0198765432', '', '', $course, ''],
            ['Cici Wong', '', '', '', '', ''],
        ];
    }

    public function headings(): array
    {
        return ['name', 'phone', 'ic_number', 'candidate_number', 'course_code', 'expires_at'];
    }

    /**
     * Force phone / IC / candidate / expires_at columns to text so Excel
     * doesn't strip leading zeros or auto-convert YYYY-MM-DD dates into
     * date serial numbers. Whatever the admin types is what we import.
     */
    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_TEXT, // phone
            'C' => NumberFormat::FORMAT_TEXT, // ic_number
            'D' => NumberFormat::FORMAT_TEXT, // candidate_number
            'F' => NumberFormat::FORMAT_TEXT, // expires_at
        ];
    }
}
