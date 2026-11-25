<?php

namespace App\Site;

use App\Carousel\Slides;
use App\Models\Department;
use App\Models\Member;
use App\OrgChart\OrgTree;
use App\Storage\Storage;
use App\Storage\Variants;

/** The public Department reads: the Department summary and the Department page (with its Org chart). */
final class DepartmentPages
{
    private const PAGE_POSTS = 6;

    private const COLUMNS = ['id', 'name', 'slug', 'description', 'logo_path', 'updated_at'];

    /** @return list<array> */
    public static function summaries(): array
    {
        return Department::query()->orderBy('name')->get(self::COLUMNS)
            ->map(fn (Department $row) => self::summary($row))->all();
    }

    /**
     * A Department's page: its Slides, People, hero sections and its 6 newest Posts, with the total for "View all".
     *
     * @return array{department: array, carousel: array, posts: array, postCount: int, members: array, heroSections: array, layout: array}|null
     */
    public static function read(string $slug): ?array
    {
        $row = Department::query()->where('slug', $slug)->first([...self::COLUMNS, 'hero_content']);
        if (! $row) {
            return null;
        }
        $layout = SiteLayout::read();

        return [
            'department' => [...self::summary($row), 'heroContent' => $row->hero_content],
            'carousel' => Slides::findSlides(['departmentId' => $row->id]),
            'posts' => Posts::newest(self::PAGE_POSTS, $row->id),
            'postCount' => Posts::listed()->where('department_id', $row->id)->count(),
            'members' => self::orgChart($row->id),
            'heroSections' => HeroSections::active($row->id),
            'layout' => $layout,
        ];
    }

    /** The Department's Org chart: Member nodes nested under their Manager, with image URLs resolved. */
    private static function orgChart(int $departmentId): array
    {
        $memberRows = Member::query()->where('department_id', $departmentId)
            ->get(['id', 'name', 'role', 'image_path', 'parent_id', 'order'])
            ->map(fn (Member $m) => [
                'id' => $m->id, 'name' => $m->name, 'role' => $m->role, 'imagePath' => $m->image_path,
                'parentId' => $m->parent_id, 'order' => $m->order,
            ])->all();

        $toNode = function (array $node) use (&$toNode): array {
            $member = $node['member'];

            return [
                'id' => $member['id'],
                'name' => $member['name'],
                'role' => $member['role'],
                'imageUrl' => Storage::publicUrl($member['imagePath']),
                'imageSmallUrl' => Storage::variantUrl($member['imagePath'], Variants::THUMB_WIDTH),
                'children' => array_map($toNode, $node['children']),
            ];
        };

        return array_map($toNode, OrgTree::buildOrgTree($memberRows));
    }

    private static function summary(Department $row): array
    {
        return [
            'id' => $row->id,
            'name' => $row->name,
            'slug' => $row->slug,
            'description' => $row->description,
            'logoUrl' => Storage::publicUrl($row->logo_path),
            'logoSmallUrl' => Storage::variantUrl($row->logo_path, Variants::THUMB_WIDTH),
            'updatedAt' => $row->updated_at,
        ];
    }
}
