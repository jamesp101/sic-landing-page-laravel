<?php

// Sign-in pages and account settings. Controllers: App\Http\Controllers\Auth\*, App\Http\Controllers\Settings\*.
// The Svelte forms submit through actions (`auth.*`, `account.*` in App\Actions); `/login` and `/logout` also
// accept a plain form POST so they work without JavaScript.
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Settings\SettingsController;
use Illuminate\Support\Facades\Route;

// Guest pages. No `guest` middleware: a signed-in visitor of /login goes to the safe `redirect` target
// (LoginController), not to a fixed page.
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store']);
Route::get('/forgot-password', [PasswordController::class, 'forgot'])->name('password.request');
Route::get('/reset-password', [PasswordController::class, 'reset'])->name('password.reset');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/confirm-password', [PasswordController::class, 'confirm'])->name('password.confirm');
    Route::get('/verify-email', [VerifyEmailController::class, 'show'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [VerifyEmailController::class, 'verify'])
        ->middleware('signed:relative')->name('verification.verify');

    Route::redirect('/settings', '/settings/profile');
    Route::get('/settings/profile', [SettingsController::class, 'profile'])->name('settings.profile');
    Route::get('/settings/security', [SettingsController::class, 'security'])->name('settings.security');
    Route::get('/settings/appearance', [SettingsController::class, 'appearance'])->name('settings.appearance');
});
