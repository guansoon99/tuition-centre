<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\User;
use App\Services\UsernameGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::create([
            'username' => 'admin',
            'name' => 'Centre Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        $teachers = collect(['cikgu_aini', 'cikgu_kumar', 'cikgu_lim'])->map(function (string $username, int $i) {
            $teacher = User::create([
                'username' => $username,
                'name' => 'Teacher '.ucfirst(explode('_', $username)[1]),
                'email' => $username.'@example.test',
                'password' => 'password',
                'is_active' => true,
            ]);
            $teacher->assignRole('teacher');

            return $teacher;
        });

        $generator = app(UsernameGenerator::class);

        $students = collect(range(1, 20))->map(function (int $i) use ($generator) {
            $username = $generator->generateForStudent();

            $student = User::create([
                'username' => $username,
                'name' => fake()->name(),
                'email' => null,
                'phone' => fake()->numerify('01#-#######'),
                'password' => 'password',
                'is_active' => true,
            ]);
            $student->assignRole('student');

            return $student;
        });

        $courses = collect([
            ['code' => 'PA-S1-A', 'name' => 'Pengajian Am Sem 1 — Kelas A'],
            ['code' => 'PA-S2-A', 'name' => 'Pengajian Am Sem 2 — Kelas A'],
            ['code' => 'SEJ-S1', 'name' => 'Sejarah Sem 1'],
            ['code' => 'BM-STPM', 'name' => 'Bahasa Melayu STPM'],
        ])->map(function (array $row) {
            return Course::create([
                'slug' => Str::slug($row['name']),
                'code' => $row['code'],
                'name' => $row['name'],
                'description' => 'Demo course '.$row['code'],
                'is_active' => true,
            ]);
        });

        $courses[0]->teachers()->attach($teachers[0]->id, ['assigned_at' => now()]);
        $courses[1]->teachers()->attach($teachers[0]->id, ['assigned_at' => now()]);
        $courses[2]->teachers()->attach($teachers[1]->id, ['assigned_at' => now()]);
        $courses[3]->teachers()->attach($teachers[2]->id, ['assigned_at' => now()]);

        foreach ($students as $student) {
            $student->enrolledCourses()->attach(
                $courses->random(rand(2, 3))->pluck('id')->all(),
                ['enrolled_at' => now(), 'is_active' => true]
            );
        }

        foreach ($courses as $course) {
            for ($week = 1; $week <= 4; $week++) {
                $section = Section::create([
                    'course_id' => $course->id,
                    'title' => "Minggu {$week} 第 {$week} 堂课 (".now()->subWeeks(5 - $week)->format('Y-m-d').')',
                    'description' => $week === 1
                        ? 'Tekan sini untuk recording video Minggu ini: https://drive.google.com/file/d/example/view'
                        : null,
                    'sort_order' => $week,
                    'is_published' => true,
                ]);

                Material::create([
                    'section_id' => $section->id,
                    'title' => "【上课资料】Petunjuk Minggu {$week}",
                    'type' => Material::TYPE_PDF,
                    'file_path' => 'materials/'.$course->id.'/'.$section->id.'/'.Str::uuid().'.pdf',
                    'file_size_bytes' => fake()->numberBetween(200_000, 2_000_000),
                    'sort_order' => 1,
                    'is_published' => true,
                    'published_at' => now(),
                    'uploaded_by_user_id' => $course->teachers()->first()?->id,
                ]);

                Material::create([
                    'section_id' => $section->id,
                    'title' => "Cadangan Jawapan Minggu {$week}",
                    'type' => Material::TYPE_PDF,
                    'file_path' => 'materials/'.$course->id.'/'.$section->id.'/'.Str::uuid().'.pdf',
                    'file_size_bytes' => fake()->numberBetween(100_000, 1_000_000),
                    'sort_order' => 2,
                    'is_published' => true,
                    'published_at' => now(),
                    'uploaded_by_user_id' => $course->teachers()->first()?->id,
                ]);

                if ($week === 1) {
                    Material::create([
                        'section_id' => $section->id,
                        'title' => 'Video Recording',
                        'type' => Material::TYPE_EXTERNAL_LINK,
                        'external_url' => 'https://drive.google.com/file/d/example/view',
                        'sort_order' => 0,
                        'is_published' => true,
                        'published_at' => now(),
                        'uploaded_by_user_id' => $course->teachers()->first()?->id,
                    ]);
                }
            }
        }

        $this->command->info(sprintf(
            'Seeded: 1 admin, %d teachers, %d students, %d courses.',
            $teachers->count(),
            $students->count(),
            $courses->count()
        ));
    }
}
