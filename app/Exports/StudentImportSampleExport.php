<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentImportSampleExport implements FromArray, WithHeadings
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
}
