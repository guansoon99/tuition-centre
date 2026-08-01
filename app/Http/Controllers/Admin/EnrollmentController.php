<?php

namespace App\Http\Controllers\Admin;

use App\Exports\EnrollmentImportSampleExport;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EnrollmentController extends Controller
{
    public function store(Request $request, Course $course): RedirectResponse
    {
        $this->authorizeCourseAccess($request->user(), $course);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'enrolled_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:enrolled_at'],
        ]);

        $student = User::findOrFail($data['user_id']);
        abort_unless($student->hasRole('student'), 422, 'User is not a student.');

        Enrollment::firstOrCreate(
            ['user_id' => $student->id, 'course_id' => $course->id],
            [
                'enrolled_at' => $data['enrolled_at'] ?? now(),
                'expires_at' => $data['expires_at'] ?? null,
                'is_active' => true,
            ]
        );

        return back()->with('status', "Enrolled {$student->username}.");
    }

    public function update(Request $request, Course $course, Enrollment $enrollment): RedirectResponse
    {
        $this->authorizeCourseAccess($request->user(), $course);
        abort_unless($enrollment->course_id === $course->id, 404);

        $data = $request->validate([
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $enrollment->update([
            'expires_at' => $data['expires_at'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', 'Enrollment updated.');
    }

    public function destroy(Request $request, Course $course, Enrollment $enrollment): RedirectResponse
    {
        $this->authorizeCourseAccess($request->user(), $course);
        abort_unless($enrollment->course_id === $course->id, 404);

        $enrollment->delete();

        return back()->with('status', 'Enrollment removed.');
    }

    /**
     * Bulk-enroll existing students by username via Excel upload.
     * Excel format: one column of usernames, header row optional (any row
     * whose value doesn't resolve to a student is reported and skipped).
     */
    public function bulkImport(Request $request, Course $course): RedirectResponse
    {
        $this->authorizeCourseAccess($request->user(), $course);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $sheet = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        $results = ['enrolled' => 0, 'reactivated' => 0, 'already' => 0, 'skipped' => []];
        $now = now();
        $expiresAt = ! empty($data['expires_at']) ? \Carbon\Carbon::parse($data['expires_at']) : null;

        foreach ($rows as $i => $row) {
            $line = $i + 1;
            $username = strtolower(trim((string) ($row[0] ?? '')));

            // Skip empty rows and skip a header row whose value is literally 'username'.
            if ($username === '' || $username === 'username') {
                continue;
            }

            $user = User::where('username', $username)->first();
            if (! $user) {
                $results['skipped'][] = ['line' => $line, 'username' => $username, 'reason' => 'Not found.'];
                continue;
            }
            if (! $user->hasRole('student')) {
                $results['skipped'][] = ['line' => $line, 'username' => $username, 'reason' => 'Not a student.'];
                continue;
            }

            $existing = Enrollment::where('user_id', $user->id)->where('course_id', $course->id)->first();
            if ($existing) {
                if ($existing->is_active) {
                    $results['already']++;
                } else {
                    $existing->update([
                        'is_active' => true,
                        'expires_at' => $expiresAt,
                    ]);
                    $results['reactivated']++;
                }
                continue;
            }

            Enrollment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'enrolled_at' => $now,
                'expires_at' => $expiresAt,
                'is_active' => true,
            ]);
            $results['enrolled']++;
        }

        return back()
            ->with('status', "Import: {$results['enrolled']} enrolled, {$results['reactivated']} reactivated, {$results['already']} already-enrolled, ".count($results['skipped']).' skipped.')
            ->with('enrollment_import_result', $results);
    }

    /**
     * Download a small Excel template with a single `username` column
     * seeded with a few example rows. Handed out from the bulk-enroll modal.
     */
    public function importTemplate(Request $request, Course $course): BinaryFileResponse
    {
        $this->authorizeCourseAccess($request->user(), $course);

        return Excel::download(new EnrollmentImportSampleExport(), 'enroll-template.xlsx');
    }

    /**
     * Teachers can only manage enrollments for active courses they're
     * assigned to. Admin bypasses.
     */
    private function authorizeCourseAccess(User $user, Course $course): void
    {
        if ($user->hasRole('admin')) {
            return;
        }
        abort_unless($user->teaches($course) && $course->is_active, 403);
    }
}
