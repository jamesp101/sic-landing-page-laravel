<?php

namespace App\Actions;

use App\Admin\ActionField;
use App\Admin\ActionInput;
use App\Admin\DepartmentAccess;
use App\Content\Content;
use App\Content\ContentField;
use App\Models\HeroSection;
use App\Support\Flash;
use Illuminate\Http\Request;

/** `heroSections.*` actions (JSON). */
class HeroSectionsActions
{
    #[Action(Access::User)]
    public function create(Request $request): array
    {
        $input = $this->input($request, false);
        $actor = $request->user();
        $departmentId = DepartmentAccess::resolveWriteDepartment($actor, $input['departmentId'] ?? null);
        $section = HeroSection::query()->create($this->data($input) + ['department_id' => $departmentId]);
        Flash::success('Hero section created successfully.');

        return ['id' => $section->id];
    }

    #[Action(Access::User)]
    public function update(Request $request): array
    {
        $input = $this->input($request, true);
        $actor = $request->user();
        /** @var HeroSection $section */
        $section = DepartmentAccess::loadScoped($actor, HeroSection::class, $input['id']);
        $departmentId = DepartmentAccess::resolveWriteDepartment($actor, $input['departmentId'] ?? null);
        $section->update($this->data($input) + ['department_id' => $departmentId]);
        Flash::success('Hero section updated successfully.');

        return ['id' => $section->id];
    }

    #[Action(Access::User)]
    public function delete(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => ActionField::id()]);
        $actor = $request->user();
        /** @var HeroSection $section */
        $section = DepartmentAccess::loadScoped($actor, HeroSection::class, $input['id']);
        $section->delete();
        Flash::success('Hero section deleted successfully.');

        return ['id' => $input['id']];
    }

    private function input(Request $request, bool $withId): array
    {
        return ActionInput::validate($request, [
            'title' => ActionField::string(['nullable', 'string', 'max:255']),
            'content' => ActionField::text(ContentField::rules(true), ['required' => 'The content is required.']),
            'order' => ActionField::int(['required', 'integer'], ['required' => 'The order must be a whole number.', 'integer' => 'The order must be a whole number.']),
            'isActive' => ActionField::bool(['required', 'boolean']),
            'departmentId' => ActionField::int(['nullable', 'integer']),
        ] + ($withId ? ['id' => ActionField::id()] : []));
    }

    private function data(array $input): array
    {
        $content = Content::forStorage($input['content']);

        return [
            'title' => $input['title'] ?? null,
            'content' => $content->value,
            'search_text' => $content->searchText,
            'order' => $input['order'],
            'is_active' => $input['isActive'],
        ];
    }
}
