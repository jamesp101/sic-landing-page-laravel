<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Site\Downloads;
use App\Site\PostFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DownloadsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $filters = PostFilters::parseDownloadFilters(PostFilters::queryParams($request->server->get('QUERY_STRING')));
        $listing = Downloads::getDownloadsPage($filters);

        return view('site.downloads', ['layout' => $listing['layout'], 'seo' => $listing['seo'], 'listing' => $listing, 'filters' => $filters]);
    }
}
