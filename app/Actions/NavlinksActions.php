<?php

namespace App\Actions;

use App\Admin\ActionField;
use App\Admin\ActionInput;
use App\Admin\NavLinkRules;
use App\Admin\NavTree;
use App\Models\NavigationLink;
use App\Site\SiteNav;
use App\Support\Flash;
use App\Support\RequestError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** `navlinks.*` actions (JSON). Superadmins only. */
class NavlinksActions
{
    #[Action(Access::Superadmin)]
    public function create(Request $request): array
    {
        $input = $this->input($request, false);
        NavLinkRules::assertValidParent($input['parentId'] ?? null);
        $link = NavigationLink::query()->create($this->columns($input));
        Flash::success('Navigation link created.');

        return ['id' => $link->id];
    }

    #[Action(Access::Superadmin)]
    public function update(Request $request): array
    {
        $input = $this->input($request, true);
        $link = NavigationLink::query()->find($input['id'], ['id']);
        if (! $link) {
            throw RequestError::notFound();
        }
        NavLinkRules::assertValidParent($input['parentId'] ?? null, $link->id);
        $link->update($this->columns($input));
        Flash::success('Navigation link updated.');

        return ['id' => $link->id];
    }

    /** Applies the whole tree (parent + position of every listed link) in one transaction. */
    #[Action(Access::Superadmin)]
    public function reorder(Request $request): array
    {
        $input = ActionInput::validate($request, [
            'items' => ActionField::list(500, [
                '*.id' => ['required', ActionField::jsonInt()],
                '*.parentId' => ['present', 'nullable', ActionField::jsonInt()],
                '*.order' => ['required', ActionField::jsonInt(), 'numeric', 'min:0'],
            ], ['*.order.min' => 'Too small: expected number to be >=0']),
        ]);
        $items = array_map(fn (array $item): array => [
            'id' => (int) $item['id'],
            'parentId' => $item['parentId'] === null ? null : (int) $item['parentId'],
            'order' => (int) $item['order'],
        ], array_values($input['items']));

        DB::transaction(function () use ($items): void {
            $existing = NavigationLink::query()->get(['id', 'parent_id'])
                ->map(fn (NavigationLink $l): array => ['id' => (int) $l->id, 'parentId' => $l->parent_id === null ? null : (int) $l->parent_id])
                ->all();
            $problem = NavTree::reorderError($items, $existing);
            if ($problem !== null) {
                throw RequestError::badRequest($problem);
            }
            foreach ($items as $item) {
                NavigationLink::query()->whereKey($item['id'])->update(['parent_id' => $item['parentId'], 'order' => $item['order']]);
            }
        });

        return ['count' => count($items)];
    }

    /**
     * Creates the Default menu (`SiteNav::DEFAULT_NAV_LINKS`) in one transaction; refused while any Navigation link exists.
     * Returns the new tree so the editor can refresh from one request.
     */
    #[Action(Access::Superadmin)]
    public function useDefaultMenu(Request $request): array
    {
        DB::transaction(function (): void {
            if (NavigationLink::query()->lockForUpdate()->exists()) {
                throw RequestError::conflict('Navigation links already exist, so the default menu was not created.');
            }
            foreach (SiteNav::DEFAULT_NAV_LINKS as $index => $link) {
                NavigationLink::query()->create([
                    'label' => $link['label'],
                    'url' => $link['url'],
                    'order' => $index + 1,
                    'is_active' => true,
                    'parent_id' => null,
                ]);
            }
        });
        Flash::success('Default menu created.');

        return ['links' => NavTree::stored()];
    }

    #[Action(Access::Superadmin)]
    public function delete(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => ActionField::id()]);
        $link = NavigationLink::query()->find($input['id'], ['id']);
        if (! $link) {
            throw RequestError::notFound();
        }
        $link->delete();
        Flash::success('Navigation link deleted.');

        return ['id' => $input['id']];
    }

    private function input(Request $request, bool $withId): array
    {
        return ActionInput::validate($request, [
            'label' => ActionField::string(['bail', 'required', 'string', 'max:255'], ['required' => 'The label is required.']),
            'url' => ActionField::string(['bail', 'required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                if (NavTree::navUrlError((string) $value) !== null) {
                    $fail(NavTree::URL_ERROR);
                }
            }], ['required' => 'The URL is required.']),
            'order' => ActionField::int(['required', 'integer'], ['required' => 'The order must be a number.', 'integer' => 'The order must be a number.']),
            'isActive' => ActionField::bool(['required', 'boolean']),
            'parentId' => ActionField::int(['nullable', 'integer']),
        ] + ($withId ? ['id' => ActionField::id()] : []));
    }

    private function columns(array $input): array
    {
        return [
            'label' => $input['label'],
            'url' => $input['url'],
            'order' => $input['order'],
            'is_active' => $input['isActive'],
            'parent_id' => $input['parentId'] ?? null,
        ];
    }
}
