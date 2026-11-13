<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Site\HomePage;
use App\Site\Seo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $data = HomePage::read();

        return view('site.home', [...$data, 'seo' => Seo::homePageSeo(Seo::siteUrl(Seo::context($request)))]);
    }
}
