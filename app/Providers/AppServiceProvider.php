<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // A server that must serve the built assets while a Vite dev server runs next to it (the e2e server
        // beside `devenv up`) points this at a path that does not exist, so `public/hot` is ignored.
        if (is_string($hotFile = config('sic.vite_hot_file')) && $hotFile !== '') {
            Vite::useHotFile($hotFile);
        }
    }
}
