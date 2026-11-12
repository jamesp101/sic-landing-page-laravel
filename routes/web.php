<?php

// Public site. Controllers: App\Http\Controllers\Site\*.
use App\Http\Controllers\CsrfTokenController;
use App\Http\Controllers\Site\ContactController;
use App\Http\Controllers\Site\DepartmentController;
use App\Http\Controllers\Site\DownloadsController;
use App\Http\Controllers\Site\FeedController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\PostController;
use App\Http\Controllers\Site\StyleguideController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, '__invoke'])->name('home');
Route::get('/departments/{slug}', [DepartmentController::class, '__invoke'])->name('departments.show');
Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
Route::get('/posts/{slug}', [PostController::class, 'show'])->name('posts.show');
Route::get('/downloads', [DownloadsController::class, '__invoke'])->name('downloads');
Route::get('/contact', [ContactController::class, '__invoke'])->name('contact');
// No-JS path of the contact form: a plain form POST. Cached public HTML carries no CSRF token, so the request
// passes the forgery check only as a same-origin browser submit (`Sec-Fetch-Site: same-origin`).
Route::post('/contact', [ContactController::class, 'submit'])->name('contact.submit');
// 404 outside the local environment.
Route::get('/styleguide', [StyleguideController::class, '__invoke'])->name('styleguide');
// Public pages are cached without a token; forms on them fetch one here (resources/js/lib/csrf.ts).
Route::get('/csrf-token', [CsrfTokenController::class, '__invoke'])->name('csrf-token');

Route::get('/sitemap.xml', [FeedController::class, 'sitemap'])->name('sitemap');
Route::get('/rss.xml', [FeedController::class, 'rss'])->name('rss');
Route::get('/robots.txt', [FeedController::class, 'robots'])->name('robots');
Route::get('/manifest.webmanifest', [FeedController::class, 'manifest'])->name('manifest');

// Unknown URLs run through the `web` group too, so the 404 page carries the request policy headers. Any method:
// a POST to an unknown URL is a 404, not a 405.
Route::any('{fallbackPlaceholder}', fn () => abort(404))->where('fallbackPlaceholder', '.*')->fallback();
