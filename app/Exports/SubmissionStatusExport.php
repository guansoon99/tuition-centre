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
            ->with('roles')
            ->orderBy('users.name')
            ->get()
            ->map(function ($student) use ($submitted) {
                $student->has_submitted = in_array($student->id, $submitted, true);

                return $student;
            });
    }

    public function headings(): array
    {
        return [
            'Name',
            'Username',
            'Password',
            // Keep this list and map() in the same order — they are positional,
            // so inserting into one alone shifts every later column's data
            // under the wrong heading without any error.
            'Phone',
            'Email',
            'IC Number',
            'Active',
            'Status',
        ];
    }

    public function map($student): array
    {
        return [
            $student->name,
            $student->username,
            // Only tracked for student users; anyone else shows blank so a
            // staff password can never leak out through a course roster.
            // Same rule as UsersExport.
            $student->roles->first()?->name === 'student' ? $student->plain_password : null,
            $student->phone,
            $student->email,
            $student->ic_number,
            $student->is_active ? 'Yes' : 'No',
            $student->has_submitted ? self::SUBMITTED : self::NOT_SUBMITTED,
        ];
    }

    /**
     * Anything that is digits-but-not-a-number has to be forced to text.
     *
     * Excel reads "student001" as a number and shows 1, eats the leading zero
     * off a phone number, and renders a 12-digit IC in scientific notation —
     * all silently, and all destroying the exact values someone downloads this
     * file to use. Passwords get the same treatment since generated ones can
     * be entirely numeric.
     *
     * Letters follow headings() positionally: B username, C password,
     * D phone, F IC number.
     */
    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_TEXT,
            'C' => NumberFormat::FORMAT_TEXT,
            'D' => NumberFormat::FORMAT_TEXT,
            'F' => NumberFormat::FORMAT_TEXT,
        ];
    }
}
