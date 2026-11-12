<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\RequestError;
use Illuminate\Contracts\View\View;

class StyleguideController extends Controller
{
    public function __invoke(): View
    {
        if (! app()->environment('local')) {
            throw RequestError::notFound();
        }

        return view('site.styleguide');
    }
}
