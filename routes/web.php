<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AccessLogController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\CourseController as AdminCourseController;
use App\Http\Controllers\Admin\CourseTeacherController;
use App\Http\Controllers\Admin\EnrollmentController;
use App\Http\Controllers\Admin\ImportStudentsController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Student\CourseController;
use App\Http\Controllers\Student\HomeController;
use App\Http\Controllers\Student\MaterialController;
use App\Http\Controllers\Teacher\MaterialController as TeacherMaterialController;
use App\Http\Controllers\Teacher\SectionController as TeacherSectionController;
use Illuminate\Support\Facades\Route;

// Public landing (guests) + dashboard (auth) — controller branches on auth state.
// `active` middleware still runs so a deactivated logged-in user gets kicked out.
Route::middleware('active')->get('/', [HomeController::class, 'index'])->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::post('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');

    Route::get('/materials/{material}', [MaterialController::class, 'view'])->name('materials.view');
    Route::get('/materials/{material}/download', [MaterialController::class, 'download'])->name('materials.download');
    Route::get('/materials/{material}/demo', [MaterialController::class, 'demoPlaceholder'])
        ->name('materials.demo-placeholder');
    Route::get('/materials/{material}/stream', [MaterialController::class, 'demoStream'])
        ->name('materials.demo-stream');

    // -----------------------------------------------------------------
    // Courses
    // -----------------------------------------------------------------

    // /courses/create + POST /courses must come before /courses/{slug}.
    // Course CRUD (create/update/destroy/activate) stays admin-only — no
    // matching permission exists in the catalog.
    Route::middleware('role:admin')->group(function () {
        Route::get('/courses/create', [AdminCourseController::class, 'create'])->name('courses.create');
        Route::post('/courses', [AdminCourseController::class, 'store'])->name('courses.store');
    });

    // Course list and edit page — anyone with courses.view can open both.
    // Per-tab actions inside are gated by the finer perms further below.
    Route::middleware('permission:courses.view')->group(function () {
        Route::get('/courses', [AdminCourseController::class, 'index'])->name('courses.index');
        Route::get('/courses/{course:slug}/edit', [AdminCourseController::class, 'edit'])->name('courses.edit');
    });

    // Course content view — auth only; CoursePolicy@view decides who passes.
    Route::get('/courses/{course:slug}', [CourseController::class, 'show'])->name('courses.show');

    // Section + material CRUD — `sections.manage` permission + teaches() scope
    // enforced by the policy.
    Route::middleware('permission:sections.manage')->group(function () {
        Route::get('/courses/{course:slug}/sections/create', [TeacherSectionController::class, 'create'])->name('sections.create');
        Route::post('/courses/{course:slug}/sections', [TeacherSectionController::class, 'store'])->name('sections.store');
        Route::post('/courses/{course:slug}/sections/quick-insert', [TeacherSectionController::class, 'quickInsert'])
            ->name('sections.quick-insert');
        Route::post('/sections/upload-image', [TeacherSectionController::class, 'uploadImage'])
            ->name('sections.upload-image');
        Route::get('/sections/{section}/edit', [TeacherSectionController::class, 'edit'])->name('sections.edit');
        Route::patch('/sections/{section}', [TeacherSectionController::class, 'update'])->name('sections.update');
        Route::delete('/sections/{section}', [TeacherSectionController::class, 'destroy'])->name('sections.destroy');

        Route::get('/sections/{section}/materials/create', [TeacherMaterialController::class, 'create'])->name('materials.create');
        Route::post('/sections/{section}/materials', [TeacherMaterialController::class, 'store'])->name('materials.store');
        Route::patch('/sections/{section}/materials/reorder', [TeacherMaterialController::class, 'reorder'])->name('materials.reorder');
        Route::get('/materials/{material}/edit', [TeacherMaterialController::class, 'edit'])->name('materials.edit');
        Route::patch('/materials/{material}', [TeacherMaterialController::class, 'update'])->name('materials.update');
        Route::delete('/materials/{material}', [TeacherMaterialController::class, 'destroy'])->name('materials.destroy');
    });

    // Course staff management — admins only.
    Route::middleware('permission:courses.manage_teachers')->group(function () {
        Route::post('/courses/{course:slug}/teachers', [CourseTeacherController::class, 'store'])
            ->name('courses.teachers.store');
        Route::delete('/courses/{course:slug}/teachers/{user}', [CourseTeacherController::class, 'destroy'])
            ->name('courses.teachers.destroy');
    });

    // Enrollment management — `courses.manage_students` + teaches() scope.
    Route::middleware('permission:courses.manage_students')->group(function () {
        Route::post('/courses/{course:slug}/enrollments', [EnrollmentController::class, 'store'])
            ->name('courses.enrollments.store');
        Route::patch('/courses/{course:slug}/enrollments/{enrollment}', [EnrollmentController::class, 'update'])
            ->name('courses.enrollments.update');
        Route::delete('/courses/{course:slug}/enrollments/{enrollment}', [EnrollmentController::class, 'destroy'])
            ->name('courses.enrollments.destroy');
    });

    // Course field edits (code, name, description, banner).
    Route::middleware('permission:courses.manage_details')->group(function () {
        Route::patch('/courses/{course:slug}', [AdminCourseController::class, 'update'])->name('courses.update');
    });
    Route::middleware('permission:courses.activate')->group(function () {
        // destroy = soft-deactivate; activate = re-enable.
        Route::delete('/courses/{course:slug}', [AdminCourseController::class, 'destroy'])->name('courses.destroy');
        Route::post('/courses/{course:slug}/activate', [AdminCourseController::class, 'activate'])->name('courses.activate');
    });

    // Access logs has no dedicated permission yet — admin only.
    Route::middleware('role:admin')->group(function () {
        Route::get('/access-logs', [AccessLogController::class, 'index'])->name('access-logs.index');
    });

    // Users — /users/create must come before /users/{user} to avoid the
    // slug route catching it.
    Route::middleware('permission:users.create')->group(function () {
        Route::get('/users/create', [AdminUserController::class, 'create'])->name('users.create');
        Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
    });
    Route::middleware('permission:users.export')->group(function () {
        Route::get('/users/export', [AdminUserController::class, 'export'])->name('users.export');
    });
    Route::middleware('permission:users.view')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
    });
    Route::middleware('permission:users.edit')->group(function () {
        Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
        Route::patch('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
    });
    Route::middleware('permission:users.deactivate')->group(function () {
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/{user}/activate', [AdminUserController::class, 'activate'])->name('users.activate');
    });

    // Banner — split per-action so admins can grant view/create/edit/delete separately.
    // Static /banner/create MUST come before the /{slide} show route to avoid
    // being caught by the parameterised route.
    Route::middleware('permission:banner.create')->group(function () {
        Route::get('/banner/create', [BannerController::class, 'create'])->name('banner.create');
        Route::post('/banner', [BannerController::class, 'store'])->name('banner.store');
    });
    Route::middleware('permission:banner.view')->group(function () {
        Route::get('/banner', [BannerController::class, 'index'])->name('banner.index');
        Route::get('/banner/{slide}', [BannerController::class, 'show'])->name('banner.show');
    });
    Route::middleware('permission:banner.edit')->group(function () {
        Route::get('/banner/{slide}/edit', [BannerController::class, 'edit'])->name('banner.edit');
        Route::patch('/banner/{slide}', [BannerController::class, 'update'])->name('banner.update');
    });
    Route::middleware('permission:banner.delete')->group(function () {
        Route::delete('/banner/{slide}', [BannerController::class, 'destroy'])->name('banner.destroy');
    });

    // Website Settings
    Route::middleware('permission:settings.view')->group(function () {
        Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
    });
    Route::middleware('permission:settings.edit')->group(function () {
        Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });

    // Announcements — split per-action.
    // Static /announcements/create MUST come before the /{announcement} show
    // route to avoid being caught by the parameterised route.
    Route::middleware('permission:announcements.create')->group(function () {
        Route::get('/announcements/create', [AnnouncementController::class, 'create'])->name('announcements.create');
        Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
    });
    Route::middleware('permission:announcements.view')->group(function () {
        Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show'])->name('announcements.show');
    });
    Route::middleware('permission:announcements.edit')->group(function () {
        Route::get('/announcements/{announcement}/edit', [AnnouncementController::class, 'edit'])->name('announcements.edit');
        Route::patch('/announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
    });
    Route::middleware('permission:announcements.delete')->group(function () {
        Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');
    });

    // Roles — split per-action. Static /roles/create MUST come before the
    // /roles/{role}/edit slug routes to avoid slug collisions.
    Route::middleware('permission:roles.create')->group(function () {
        Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    });
    Route::middleware('permission:roles.view')->group(function () {
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    });
    Route::middleware('permission:roles.edit')->group(function () {
        Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
        Route::patch('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    });
    Route::middleware('permission:roles.delete')->group(function () {
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    // Import students
    Route::middleware('permission:users.import')->group(function () {
        Route::get('/import-students', [ImportStudentsController::class, 'show'])->name('import.show');
        Route::get('/import-students/sample', [ImportStudentsController::class, 'downloadSample'])->name('import.sample');
        Route::post('/import-students/preview', [ImportStudentsController::class, 'preview'])->name('import.preview');
        Route::post('/import-students/run', [ImportStudentsController::class, 'run'])->name('import.run');
        Route::post('/import-students/cancel', [ImportStudentsController::class, 'cancel'])->name('import.cancel');
        Route::get('/import-students/credentials', [ImportStudentsController::class, 'downloadCredentials'])->name('import.credentials');
    });
});
