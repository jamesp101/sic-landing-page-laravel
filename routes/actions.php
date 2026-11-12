<?php

use App\Http\Controllers\ActionController;
use Illuminate\Support\Facades\Route;

// `posts.create` → App\Actions\PostsActions::create(). See ActionController for the contract.
Route::post('/_actions/{name}', [ActionController::class, '__invoke'])->where('name', '[A-Za-z.]+')->name('actions');
