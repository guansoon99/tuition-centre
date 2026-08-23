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

        // The third row deliberately leaves everything but the name blank —
        // every column except name is optional, email included.
        return [
            ['Ali Bin Ahmad', '0123456789', 'ali@example.com', '050101011234', 'BA001A001', $course, '2027-01-31'],
            ['Bee Chen Lim', '0198765432', '', '', '', $course, ''],
            ['Cici Wong', '', '', '', '', '', ''],
        ];
    }

    public function headings(): array
    {
        return ['name', 'phone', 'email', 'ic_number', 'candidate_number', 'course_code', 'expires_at'];
    }

    /**
     * Force phone / IC / candidate / expires_at columns to text so Excel
     * doesn't strip leading zeros or auto-convert YYYY-MM-DD dates into
     * date serial numbers. Whatever the admin types is what we import.
     *
     * These are positional, so they shift whenever a column is inserted —
     * email going in at C moved IC, candidate and expires_at along one.
     * Email itself needs no format: Excel does not mangle an address.
     */
    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_TEXT, // phone
            'D' => NumberFormat::FORMAT_TEXT, // ic_number
            'E' => NumberFormat::FORMAT_TEXT, // candidate_number
            'G' => NumberFormat::FORMAT_TEXT, // expires_at
        ];
    }
}
