<?php

namespace Tests\Feature\Security;

use App\Models\Announcement;
use App\Models\BannerSlide;
use App\Models\Contact;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\FeedbackFile;
use App\Models\Material;
use App\Models\Section;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every route, walked as a user who should not be able to reach it.
 *
 * Reading middleware lists proves what was configured, not what happens. This
 * drives the real router: each route is resolved against a course the actor is
 * not part of, another student's submission, another user's record — and any
 * route answering 200 is reported by name.
 *
 * Two actors, because "can a student open the admin pages" is only half the
 * question. The other half is whether a teacher can reach a course that is not
 * theirs — role checks pass for them, so only the per-course scoping stands in
 * the way, and that is the easier layer to get wrong.
 *
 * The allow-lists are the contract. A new route showing up in a failure is the
 * point: it means a page shipped without anyone deciding who it is for.
 */
class RouteAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private array $ids = [];

    private User $student;

    private User $outsiderTeacher;

    /** Routes a signed-in student may legitimately open. */
    private const STUDENT_MAY_REACH = [
        'home', 'logout', 'account.show', 'account.password',
        'courses.index',        // scoped to their own enrolments
        'calendar.index',       // readable by all; the writes are gated
        'calendar.events',
        'sections.toggle-fold', // their own fold state, and it authorises the section
        'login',                // redirects an already-authenticated user away
    ];

    /**
     * Additionally reachable by a teacher who teaches some other course.
     *
     * Global staff screens their role grants. The point of the test is that no
     * course-scoped route ever needs to join this list.
     */
    private const TEACHER_ALSO_MAY_REACH = [
        'users.index', 'users.create', 'users.show', 'users.edit', 'users.export',
        'import.show', 'import.sample',
        'materials.create', 'materials.create-modal',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        // Everything below belongs to someone else.
        $course = Course::factory()->create(['is_active' => true, 'slug' => 'other-course']);
        $section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
        $material = Material::factory()->create([
            'section_id' => $section->id, 'type' => Material::TYPE_ASSIGNMENT,
            'is_published' => true, 'due_date' => now()->addWeek(),
        ]);

        $victim = User::factory()->create(['is_active' => true]);
        $victim->assignRole('student');
        Enrollment::create([
            'course_id' => $course->id, 'user_id' => $victim->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $submission = Submission::firstOrCreate(
            ['material_id' => $material->id, 'user_id' => $victim->id],
            ['submitted_at' => now(), 'last_modified_at' => now()],
        );
        $subFile = $submission->files()->create([
            'file_path' => "courses/{$course->id}/a/{$victim->id}/w.pdf",
            'original_name' => 'victim.pdf', 'size_bytes' => 10,
            'mime_type' => 'application/pdf', 'uploaded_at' => now(),
        ]);
        $feedbackFile = FeedbackFile::create([
            'submission_id' => $submission->id,
            'file_path' => "courses/{$course->id}/f/{$victim->id}/marked.pdf",
            'original_name' => 'marked.pdf', 'size_bytes' => 10,
            'mime_type' => 'application/pdf', 'uploaded_at' => now(),
            'uploaded_by_user_id' => $victim->id,
        ]);

        $announcement = Announcement::create([
            'title' => 'Notice', 'body' => 'x', 'type' => Announcement::TYPE_TEXT,
            'is_active' => true, 'sort_order' => 1, 'audience' => 'all',
            'created_by_user_id' => $victim->id,
        ]);
        $event = Event::create([
            'title' => 'Staff Day', 'date' => now()->addWeek()->format('Y-m-d'),
            'color' => 'blue', 'display_style' => 'pill',
            'created_by_user_id' => $victim->id,
        ]);
        $slide = BannerSlide::create([
            'image_path' => 'banner/x.jpg', 'title' => 'Slide',
            'sort_order' => 1, 'is_active' => true,
        ]);
        $contact = Contact::create([
            'type' => 'phone', 'value' => '0123456789', 'label' => 'Office',
            'sort_order' => 1, 'is_active' => true,
        ]);

        // Actor 1: a student enrolled in nothing.
        $this->student = User::factory()->create(['is_active' => true]);
        $this->student->assignRole('student');

        // Actor 2: a teacher holding the full staff role, teaching a DIFFERENT
        // course. Every role and permission check passes for them.
        $ownCourse = Course::factory()->create(['is_active' => true, 'slug' => 'their-own-course']);
        $this->outsiderTeacher = User::factory()->create(['is_active' => true]);
        $this->outsiderTeacher->assignRole('teacher');
        Enrollment::create([
            'course_id' => $ownCourse->id, 'user_id' => $this->outsiderTeacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $this->ids = [
            'course:slug' => $course->slug,
            'course:id' => (string) $course->id,
            'course' => $course->slug,
            'section' => (string) $section->id,
            'material' => (string) $material->id,
            'submission' => (string) $submission->id,
            'file' => (string) $subFile->id,
            'file:feedback' => (string) $feedbackFile->id,
            'file:media' => 'photo.jpg',
            'user' => (string) $victim->id,
            'announcement' => (string) $announcement->id,
            'event' => (string) $event->id,
            'enrollment' => (string) DB::table('enrollments')->value('id'),
            'role' => (string) Role::where('name', 'teacher')->value('id'),
            'slide' => (string) $slide->id,
            'contact' => (string) $contact->id,
            'folder' => 'materials',
        ];
    }

    /**
     * Substitute route parameters with resources the actor does not own.
     *
     * Two traps here, and both produce a 404 that reads as a pass:
     *
     *  - Route::uri() drops the binding field, so {course:id} arrives as
     *    {course}. Feed that the slug and whereNumber rejects the URL before
     *    any authorisation runs. bindingFields() is what restores it.
     *  - {file} is a SubmissionFile, a FeedbackFile, or a bare filename
     *    depending on the route, so the name alone cannot resolve it.
     */
    private function fill(string $uri, array $bindingFields): ?string
    {
        return preg_replace_callback('/\{([a-zA-Z_:]+)\??\}/', function ($m) use ($uri, $bindingFields) {
            $key = $m[1];

            if (isset($bindingFields[$key])) {
                $key .= ':'.$bindingFields[$key];
            } elseif ($key === 'file') {
                if (str_contains($uri, 'feedback-files')) {
                    $key = 'file:feedback';
                } elseif (str_contains($uri, '/media/')) {
                    $key = 'file:media';
                }
            }

            return $this->ids[$key] ?? '__none__';
        }, $uri);
    }

    /** @return array{0: list<string>, 1: int} routes that answered 200, and how many were tried */
    private function walk(User $actor, array $allowed): array
    {
        $reachable = [];
        $tried = 0;

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            $uri = $route->uri();

            if (str_contains($uri, '_ignition') || $uri === 'sanctum/csrf-cookie') {
                continue;
            }
            if ($name !== null && in_array($name, $allowed, true)) {
                continue;
            }

            $path = $this->fill($uri, $route->bindingFields());
            if ($path === null || str_contains($path, '__none__')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                // A second actingAs in one test does not take without this.
                $this->app['auth']->forgetGuards();
                $this->flushSession();

                $tried++;
                $response = $this->actingAs($actor)->call($method, '/'.ltrim($path, '/'));

                if ($response->getStatusCode() === 200) {
                    $reachable[] = sprintf('%s /%s  (%s)', $method, $path, $name ?? 'unnamed');
                }
            }
        }

        return [$reachable, $tried];
    }

    public function test_a_student_cannot_reach_anything_outside_their_own(): void
    {
        [$reachable, $tried] = $this->walk($this->student, self::STUDENT_MAY_REACH);

        // Guards the guard: if parameters stop resolving, the walk silently
        // shrinks and the test passes by testing nothing.
        $this->assertGreaterThan(80, $tried,
            "Only {$tried} route/method pairs were exercised — parameters are not resolving.");

        $this->assertSame([], $reachable,
            "A student reached routes they should not:\n  ".implode("\n  ", $reachable)."\n");
    }

    /**
     * A teacher of one course must not reach another course's.
     *
     * Role and permission checks all pass for this actor — they hold the full
     * staff role. Only per-course scoping stands between them and someone
     * else's class, which is the layer role tests never reach.
     */
    public function test_a_teacher_cannot_reach_a_course_they_do_not_teach(): void
    {
        [$reachable, $tried] = $this->walk(
            $this->outsiderTeacher,
            array_merge(self::STUDENT_MAY_REACH, self::TEACHER_ALSO_MAY_REACH),
        );

        $this->assertGreaterThan(80, $tried,
            "Only {$tried} route/method pairs were exercised — parameters are not resolving.");

        $this->assertSame([], $reachable,
            "A teacher reached another course's routes:\n  ".implode("\n  ", $reachable)."\n");
    }
}
