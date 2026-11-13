<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Site\DepartmentPages;
use App\Site\Seo;
use App\Support\RequestError;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function __invoke(Request $request, string $slug): View
    {
        $page = DepartmentPages::read($slug) ?? throw RequestError::notFound();
        $site = Seo::siteUrl(Seo::context($request));
        $seo = Seo::departmentPageSeo($page['department'], $page['members'], $page['carousel'][0]['imageUrl'] ?? null, $site);

        return view('site.departments.show', [...$page, 'seo' => $seo]);
    }
}
