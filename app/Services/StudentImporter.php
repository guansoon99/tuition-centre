<?php

namespace App\Services;

use App\Models\Course;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class StudentImporter
{
    public function __construct(
        private readonly UsernameGenerator $usernames,
        private readonly PasswordGenerator $passwords,
    ) {}

    public function preview(UploadedFile $file): array
    {
        return $this->processRows($this->parseFile($file), dryRun: true);
    }

    public function import(UploadedFile $file): array
    {
        return $this->processRows($this->parseFile($file), dryRun: false);
    }

    /**
     * Returns an array of associative rows keyed by the header row (lowercased).
     */
    public function parseFile(UploadedFile $file): array
    {
        $sheets = Excel::toArray(new \stdClass, $file);

        if (empty($sheets) || empty($sheets[0])) {
            return [];
        }

        $rows = $sheets[0];
        $header = array_map(
            fn ($v) => strtolower(trim((string) $v)),
            array_shift($rows)
        );

        $records = [];
        foreach ($rows as $row) {
            if (empty(array_filter($row, fn ($v) => $v !== null && $v !== ''))) {
                continue;
            }

            $record = [];
            foreach ($header as $i => $col) {
                $record[$col] = $row[$i] ?? null;
            }

            // Normalise expires_at so whatever Excel handed us (a plain
            // YYYY-MM-DD string, a DateTimeInterface, or a numeric date
            // serial) becomes a YYYY-MM-DD string that Carbon can parse.
            $record['expires_at'] = $this->normaliseDate($record['expires_at'] ?? null);

            $records[] = $record;
        }

        return $records;
    }

    /**
     * Turn whatever the spreadsheet handed us into a YYYY-MM-DD string.
     * Accepts: DateTimeInterface, Excel serial numbers, and a wide range
     * of hand-typed formats (2027-01-31, 31/01/2027, 31-1-2027, etc).
     * Unknown/garbage input is returned as-is so processRows() can flag
     * it as an "Invalid expires_at" error instead of silently accepting.
     */
    private function normaliseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value) && (float) $value > 0) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable) {
                // Fall through to string parsing below.
            }
        }

        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }

        // Try each format explicitly. We prefer ISO / D-M-Y since the app
        // is Malaysia-based (dd/mm/yyyy is the local convention). The
        // strict format→re-format→compare check rejects overflow like
        // 32/01/2027 that PHP would otherwise silently roll over.
        $formats = [
            'Y-m-d', 'Y/m/d', 'Y.m.d',
            'd-m-Y', 'd/m/Y', 'd.m.Y',
            'd-m-y', 'd/m/y', 'd.m.y',
            'Y-n-j', 'Y/n/j',
            'j-n-Y', 'j/n/Y',
        ];

        foreach ($formats as $fmt) {
            $d = Carbon::createFromFormat($fmt, $str);
            if ($d !== false && $d->format($fmt) === $str) {
                return $d->format('Y-m-d');
            }
        }

        // Last resort — Carbon's fuzzy parse handles named-month input
        // like "Jan 31, 2027" or "31 January 2027".
        try {
            return Carbon::parse($str)->format('Y-m-d');
        } catch (Throwable) {
            return $str;
        }
    }

    /**
     * Process parsed rows. Returns ['ok' => [...], 'skipped' => [...], 'errors' => [...]].
     * Each 'ok' row in import mode includes username + plain_password for the credentials export.
     */
    public function processRows(array $records, bool $dryRun, bool $allowDuplicates = false): array
    {
        $results = ['ok' => [], 'skipped' => [], 'errors' => []];

        // Pre-load existing names (case-insensitive) so we can flag duplicates
        // without hitting the DB once per row.
        $existingNames = User::pluck('name')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))
            ->filter()
            ->flip();
        $seenInBatch = [];

        foreach ($records as $idx => $row) {
            $line = $idx + 2;

            $name = trim((string) ($row['name'] ?? ''));
            $courseCode = trim((string) ($row['course_code'] ?? ''));

            if ($name === '') {
                $results['errors'][] = [
                    'line' => $line,
                    'name' => $name,
                    'reason' => 'Missing required field (name).',
                ];
                continue;
            }

            $course = null;
            if ($courseCode !== '') {
                $course = Course::where('code', $courseCode)->first();
                if (! $course) {
                    $results['errors'][] = [
                        'line' => $line,
                        'name' => $name,
                        'reason' => "Course code '{$courseCode}' does not exist.",
                    ];
                    continue;
                }
            }

            try {
                $expiresAt = ! empty($row['expires_at'])
                    ? Carbon::parse($row['expires_at'])
                    : null;
            } catch (Throwable) {
                $results['errors'][] = [
                    'line' => $line,
                    'name' => $name,
                    'reason' => 'Invalid expires_at — use YYYY-MM-DD.',
                ];
                continue;
            }

            // Detect duplicate names — either against existing users or
            // against earlier rows in the same file. When $allowDuplicates
            // is true (admin picked "Create everyone anyway"), we still
            // track duplicates but let them through.
            $nameKey = mb_strtolower($name);
            $isDbDuplicate = isset($existingNames[$nameKey]);
            $isBatchDuplicate = isset($seenInBatch[$nameKey]);

            if ($isDbDuplicate && ! $allowDuplicates) {
                $results['skipped'][] = [
                    'line' => $line,
                    'name' => $name,
                    'course' => $course?->code,
                    'reason' => "A user named '{$name}' already exists.",
                ];
                continue;
            }
            if ($isBatchDuplicate && ! $allowDuplicates) {
                $results['skipped'][] = [
                    'line' => $line,
                    'name' => $name,
                    'course' => $course?->code,
                    'reason' => "Duplicate name in this file (first seen at line {$seenInBatch[$nameKey]}).",
                ];
                continue;
            }
            $seenInBatch[$nameKey] = $line;

            if ($dryRun) {
                $results['ok'][] = [
                    'line' => $line,
                    'name' => $name,
                    'course' => $course?->code,
                ];
                continue;
            }

            $username = $this->usernames->generateForStudent();
            $password = $this->passwords->generate();

            $phone = trim((string) ($row['phone'] ?? ''));
            $icNumber = trim((string) ($row['ic_number'] ?? ''));
            $candidateNumber = trim((string) ($row['candidate_number'] ?? ''));

            $user = User::create([
                'username' => $username,
                'name' => $name,
                'phone' => $phone !== '' ? $phone : null,
                'ic_number' => $icNumber !== '' ? $icNumber : null,
                'candidate_number' => $candidateNumber !== '' ? $candidateNumber : null,
                'password' => $password,
                'is_active' => true,
            ]);
            $user->assignRole('student');

            if ($course) {
                $user->enrolledCourses()->attach($course->id, [
                    'enrolled_at' => now(),
                    'expires_at' => $expiresAt,
                    'is_active' => true,
                ]);
            }

            $results['ok'][] = [
                'line' => $line,
                'name' => $name,
                'username' => $username,
                'plain_password' => $password,
                'course' => $course?->code,
            ];
        }

        return $results;
    }
}
