<?php

// Stored files and upload intake. Controllers: App\Http\Controllers\Storage\*.
use App\Http\Controllers\Storage\StorageController;
use App\Http\Controllers\Storage\UploadController;
use Illuminate\Support\Facades\Route;

// Public files: Range (206/416), variant fallback, `?download=<name>`.
Route::get('/storage/{path}', [StorageController::class, '__invoke'])->where('path', '.*')->name('storage');

Route::middleware('auth')->prefix('api/admin/uploads')->group(function () {
    Route::post('/image', [UploadController::class, 'image']);
    Route::post('/direct', [UploadController::class, 'direct']);
    Route::post('/video', [UploadController::class, 'video']);
    Route::put('/video', [UploadController::class, 'videoBytes']);
    Route::post('/video/complete', [UploadController::class, 'videoComplete']);
});
