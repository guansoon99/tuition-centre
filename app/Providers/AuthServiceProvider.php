<?php

namespace App\Providers;

use App\Models\Course;
use App\Models\Material;
use App\Models\Section;
use App\Policies\CoursePolicy;
use App\Policies\MaterialPolicy;
use App\Policies\SectionPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Course::class => CoursePolicy::class,
        Section::class => SectionPolicy::class,
        Material::class => MaterialPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        // Admin auto-passes any Gate / permission / policy check.
        // Returning null falls through to normal checks for non-admins.
        // Note: this does NOT affect the Spatie `role:admin` route middleware
        // (which checks hasRole directly), only Gate-based permission checks.
        Gate::before(function ($user, $ability) {
            return $user?->hasRole('admin') ? true : null;
        });
    }
}
