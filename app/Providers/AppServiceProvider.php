<?php

namespace App\Providers;

use App\Models\Enrollment;
use App\Models\SiteSettings;
use App\Observers\EnrollmentObserver;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per-request memo for the site settings row. SiteSettings::current()
        // is hit ~5x per page render; without this each call re-reads and
        // unserializes the cached model.
        //
        // scoped() rather than singleton(): the binding is torn down at the
        // end of every request AND between queued jobs, so the memoized
        // Eloquent model can never outlive the database state it was built
        // from. That matters because callers write through this model.
        $this->app->scoped(SiteSettings::CONTAINER_KEY, fn () => Cache::remember(
            SiteSettings::CACHE_KEY,
            3600,
            fn () => SiteSettings::row()
        ));
    }

    public function boot(): void
    {
        // Course / Section / Material observers used to exist solely to bust
        // the courseDetail cache. That cache is gone (the student course page
        // queries directly), so they went with it.
        Enrollment::observe(EnrollmentObserver::class);

        // One session per account: when the account signs in elsewhere, the
        // AuthenticateSession middleware ends this session on its next
        // request. Without a redirect of its own it hands the browser a
        // blank 401 (seen on production, 2026-09-22). Send it to the plain
        // login page instead.
        AuthenticateSession::redirectUsing(fn () => route('login'));
    }
}
