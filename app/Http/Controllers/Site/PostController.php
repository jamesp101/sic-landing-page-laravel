<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Site\PostFilters;
use App\Site\Queries;
use App\Site\Seo;
use App\Support\Pagination;
use App\Support\RequestError;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PostController extends Controller
{
    /** Three full rows of the three-column grid. */
    private const PER_PAGE = 12;

    public function index(Request $request): View
    {
        $params = PostFilters::queryParams($request->server->get('QUERY_STRING'));
        $filters = PostFilters::parsePostFilters($params);
        $page = Pagination::parsePage($params['page'] ?? null);

        $listing = Queries::getPostsPage($filters, $page, self::PER_PAGE, Seo::siteUrl(Seo::context($request))) ?? throw RequestError::notFound();

        return view('site.posts.index', [...$listing, 'filters' => $filters, 'page' => $page, 'perPage' => self::PER_PAGE]);
    }

    public function show(Request $request, string $slug): View
    {
        $page = Queries::getPostPage($slug, Seo::siteUrl(Seo::context($request))) ?? throw RequestError::notFound();

        return view('site.posts.show', $page);
    }
}
