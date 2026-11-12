<?php

namespace App\Admin;

use App\Models\NavigationLink;
use App\Support\RequestError;

/** Rules for navigation links that need the database. */
final class NavLinkRules
{
    /** Navigation is two levels deep: a parent must be an existing top-level link other than the link itself. */
    public static function assertValidParent(?int $parentId, ?int $selfId = null): void
    {
        if ($parentId === null) {
            return;
        }
        if ($parentId === $selfId) {
            throw RequestError::badRequest('A link cannot be its own parent.');
        }
        $parent = NavigationLink::query()->find($parentId, ['id', 'parent_id']);
        if (! $parent) {
            throw RequestError::badRequest('The selected parent link does not exist.');
        }
        if ($parent->parent_id !== null) {
            throw RequestError::badRequest('Only top-level links can be used as a parent.');
        }
        if ($selfId !== null && NavigationLink::query()->where('parent_id', $selfId)->exists()) {
            throw RequestError::badRequest('A link that has sub-links cannot become a sub-link itself.');
        }
    }
}
