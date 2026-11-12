<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Site\Queries;
use App\Site\Seo;
use App\Support\RequestError;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function __invoke(Request $request, string $slug): View
    {
        $page = Queries::getDepartmentPage($slug, Seo::siteUrl(Seo::context($request))) ?? throw RequestError::notFound();

        return view('site.departments.show', $page);
    }
}
