<?php

namespace App\Actions;

use App\Admin\ActionInput;
use App\Admin\DepartmentAccess;
use App\Content\Content;
use App\Content\ContentField;
use App\Models\HeroSection;
use App\Support\Authz;
use App\Support\Flash;
use Illuminate\Http\Request;

/** `heroSections.*` actions (JSON). */
class HeroSectionsActions
{
    public function create(Request $request): array
    {
        $input = $this->input($request, false);
        $actor = Authz::requireUser($request->user());
        $departmentId = DepartmentAccess::resolveWriteDepartment($actor, $input['departmentId'] ?? null);
        $section = HeroSection::query()->create($this->data($input) + ['department_id' => $departmentId]);
        Flash::success('Hero section created successfully.');

        return ['id' => $section->id];
    }

    public function update(Request $request): array
    {
        $input = $this->input($request, true);
        $actor = Authz::requireUser($request->user());
        /** @var HeroSection $section */
        $section = DepartmentAccess::loadScoped($actor, 'heroSection', $input['id']);
        $departmentId = DepartmentAccess::resolveWriteDepartment($actor, $input['departmentId'] ?? null);
        $section->update($this->data($input) + ['department_id' => $departmentId]);
        Flash::success('Hero section updated successfully.');

        return ['id' => $section->id];
    }

    public function delete(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => 'int'], ['id' => ['required', 'integer']]);
        $actor = Authz::requireUser($request->user());
        /** @var HeroSection $section */
        $section = DepartmentAccess::loadScoped($actor, 'heroSection', $input['id']);
        $section->delete();
        Flash::success('Hero section deleted successfully.');

        return ['id' => $input['id']];
    }

    private function input(Request $request, bool $withId): array
    {
        $types = ['title' => 'string', 'content' => 'text', 'order' => 'int', 'isActive' => 'bool', 'departmentId' => 'int'];
        $rules = [
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ContentField::rules(true),
            'order' => ['required', 'integer'],
            'isActive' => ['required', 'boolean'],
            'departmentId' => ['nullable', 'integer'],
        ];
        if ($withId) {
            $types['id'] = 'int';
            $rules['id'] = ['required', 'integer'];
        }
        $input = ActionInput::validate($request, $types, $rules, [
            'content.required' => 'The content is required.',
            'order.required' => 'The order must be a whole number.',
            'order.integer' => 'The order must be a whole number.',
        ]);
        $input['content'] = Content::checkContent($input['content'])['value'];

        return $input;
    }

    private function data(array $input): array
    {
        return [
            'title' => $input['title'] ?? null,
            'content' => $input['content'],
            'search_text' => Content::searchTextOf($input['content']),
            'order' => $input['order'],
            'is_active' => $input['isActive'],
        ];
    }
}
