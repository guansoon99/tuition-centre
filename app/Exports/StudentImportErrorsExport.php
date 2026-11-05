<?php

namespace App\Exports;

use App\Services\StudentImporter;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * The rows of an import that could not be used, as an Excel file the admin
 * can correct and upload again. The columns start the same as the import
 * format; the importer ignores the two extra ones on the way back in.
 *
 * Every cell is written as text: a long IC number must not turn into
 * 9.00101E+11, and whatever was in the uploaded file must not become a live
 * formula in this one.
 */
class StudentImportErrorsExport extends StringValueBinder implements FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithHeadings
{
    /** @param  array<int, array{line:int, name:?string, reason:string, row?:array}>  $errors */
    public function __construct(private readonly array $errors) {}

    public function array(): array
    {
        return array_map(function (array $error) {
            // Imports run before the row was kept only have the name to give.
            $row = $error['row'] ?? ['name' => $error['name'] ?? ''];

            return [
                ...array_map(fn (string $column) => (string) ($row[$column] ?? ''), StudentImporter::ROW_COLUMNS),
                (string) ($error['reason'] ?? ''),
                (string) ($error['line'] ?? ''),
            ];
        }, $this->errors);
    }

    public function headings(): array
    {
        return [...StudentImporter::ROW_COLUMNS, 'error', 'original_line'];
    }

    /** Text columns, so Excel leaves them alone while the admin edits them. */
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
