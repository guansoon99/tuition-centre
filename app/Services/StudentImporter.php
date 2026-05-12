<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
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
            $records[] = $record;
        }

        return $records;
    }

    /**
     * Process parsed rows. Returns ['ok' => [...], 'skipped' => [...], 'errors' => [...]].
     * Each 'ok' row in import mode includes username + plain_password for the credentials export.
     */
    public function processRows(array $records, bool $dryRun): array
    {
        $results = ['ok' => [], 'skipped' => [], 'errors' => []];

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

            if ($course) {
                $duplicate = Enrollment::query()
                    ->where('course_id', $course->id)
                    ->whereHas('user', fn ($q) => $q->where('name', $name))
                    ->exists();

                if ($duplicate) {
                    $results['skipped'][] = [
                        'line' => $line,
                        'name' => $name,
                        'course' => $course->code,
                        'reason' => 'Already enrolled in this course.',
                    ];
                    continue;
                }
            }

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
