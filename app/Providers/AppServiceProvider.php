<?php

namespace App\Providers;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Observers\CourseObserver;
use App\Observers\EnrollmentObserver;
use App\Observers\MaterialObserver;
use App\Observers\SectionObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Course::observe(CourseObserver::class);
        Section::observe(SectionObserver::class);
        Material::observe(MaterialObserver::class);
        Enrollment::observe(EnrollmentObserver::class);
    }
}
