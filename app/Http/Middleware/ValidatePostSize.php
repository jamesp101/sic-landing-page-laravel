<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\ValidatePostSize as BaseValidatePostSize;

/**
 * Laravel's `post_max_size` check, except for the raw video upload (`PUT /api/admin/uploads/video`): PHP does not
 * parse that body as a form, so `post_max_size` does not apply to it; the route enforces the 100 MB it announced.
 */
class ValidatePostSize extends BaseValidatePostSize
{
    public function handle($request, Closure $next)
    {
        if ($request->isMethod('PUT') && $request->is('api/admin/uploads/video')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
