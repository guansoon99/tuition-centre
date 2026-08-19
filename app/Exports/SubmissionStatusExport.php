<?php

namespace App\Exports;

use App\Models\Material;
use App\Models\Submission;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Who has handed this assignment in, as a spreadsheet.
 *
 * The roster and the definition of "submitted" are deliberately identical to
 * the grading page (Student\MaterialController::view and the assignment view):
 * active enrolled students, ordered by name, where submitted means a
 * submission row that actually has files. A submission row with no files is a
 * student who opened the upload form and never finished, and the screen counts
 * that as not submitted — an export that disagreed with the page it sits on
 * would be worse than no export at all.
 */
class SubmissionStatusExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping
{
    public const SUBMITTED = 'Submitted';

    public const NOT_SUBMITTED = 'Not submitted';

    public function __construct(private readonly Material $material) {}

    public function collection(): Collection
    {
        $submitted = Submission::query()
            ->where('material_id', $this->material->id)
            ->has('files')
            ->pluck('user_id')
            ->all();

        return $this->material->section->course
            ->students()
            ->wherePivot('is_active', true)
            ->orderBy('users.name')
            ->get()
            ->map(function ($student) use ($submitted) {
                $student->has_submitted = in_array($student->id, $submitted, true);

                return $student;
            });
    }

    public function headings(): array
    {
        return ['Username', 'Name', 'Status'];
    }

    public function map($student): array
    {
        return [
            $student->username,
            $student->name,
            $student->has_submitted ? self::SUBMITTED : self::NOT_SUBMITTED,
        ];
    }

    /**
     * Usernames are text. Without this Excel reads "student001" as a number
     * and shows "1" — the same reason EnrollmentImportSampleExport does it.
     */
    public function columnFormats(): array
    {
        return ['A' => NumberFormat::FORMAT_TEXT];
    }
}
