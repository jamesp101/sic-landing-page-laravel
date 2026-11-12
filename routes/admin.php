<?php

// Admin pages. Controllers: App\Http\Controllers\Admin\*. Every controller method calls App\Support\Authz
// (through App\Admin\AdminPage) first; the `auth` middleware only redirects signed-out visitors to /login.
use App\Http\Controllers\Admin\CarouselController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\DownloadController;
use App\Http\Controllers\Admin\HeroSectionController;
use App\Http\Controllers\Admin\LandingSettingsController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\NavLinkController;
use App\Http\Controllers\Admin\PostController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, '__invoke'])->name('dashboard');
    Route::redirect('/admin', '/dashboard');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
        Route::get('/posts/create', [PostController::class, 'create'])->name('posts.create');
        Route::get('/posts/{id}/edit', [PostController::class, 'edit'])->name('posts.edit');

        Route::get('/hero-sections', [HeroSectionController::class, 'index'])->name('hero-sections.index');
        Route::get('/hero-sections/create', [HeroSectionController::class, 'create'])->name('hero-sections.create');
        Route::get('/hero-sections/{id}/edit', [HeroSectionController::class, 'edit'])->name('hero-sections.edit');

        Route::get('/settings/landing', [LandingSettingsController::class, '__invoke'])->name('settings.landing');

        Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::get('/departments/create', [DepartmentController::class, 'create'])->name('departments.create');
        Route::get('/departments/{id}/edit', [DepartmentController::class, 'edit'])->name('departments.edit');
        Route::get('/departments/{id}/members', [MemberController::class, '__invoke'])->name('departments.members');

        Route::get('/carousels', [CarouselController::class, '__invoke'])->name('carousels.index');
        Route::get('/navlinks', [NavLinkController::class, '__invoke'])->name('navlinks.index');

        Route::get('/downloads', [DownloadController::class, 'index'])->name('downloads.index');
        Route::get('/downloads/create', [DownloadController::class, 'create'])->name('downloads.create');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::get('/users/{id}/edit', [UserController::class, 'edit'])->name('users.edit');
    });
});
