<?php

namespace App\Actions;

use App\Admin\ActionInput;
use App\Admin\NavLinkRules;
use App\Admin\NavTree;
use App\Models\NavigationLink;
use App\Support\Authz;
use App\Support\Flash;
use App\Support\RequestError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** `navlinks.*` actions (JSON). Superadmins only. */
class NavlinksActions
{
    private const TYPES = ['label' => 'string', 'url' => 'string', 'order' => 'int', 'isActive' => 'bool', 'parentId' => 'int'];

    public function create(Request $request): array
    {
        $input = $this->input($request, false);
        Authz::requireSuperAdmin($request->user());
        NavLinkRules::assertValidParent($input['parentId'] ?? null);
        $link = NavigationLink::query()->create($this->columns($input));
        Flash::success('Navigation link created.');

        return ['id' => $link->id];
    }

    public function update(Request $request): array
    {
        $input = $this->input($request, true);
        Authz::requireSuperAdmin($request->user());
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
    public function reorder(Request $request): array
    {
        $input = ActionInput::validate(
            $request,
            ['items' => 'raw'],
            [
                'items' => ['required', 'array', 'min:1', 'max:500'],
                'items.*.id' => ['required', self::jsonInt()],
                'items.*.parentId' => ['present', 'nullable', self::jsonInt()],
                'items.*.order' => ['required', self::jsonInt(), 'numeric', 'min:0'],
            ],
            [
                'items.required' => 'Too small: expected array to have >=1 items',
                'items.min' => 'Too small: expected array to have >=1 items',
                'items.max' => 'Too big: expected array to have <=500 items',
                'items.*.order.min' => 'Too small: expected number to be >=0',
            ],
        );
        Authz::requireSuperAdmin($request->user());
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

    public function delete(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => 'int'], ['id' => ['required', 'integer']]);
        Authz::requireSuperAdmin($request->user());
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
        $types = self::TYPES;
        $rules = [
            'label' => ['bail', 'required', 'string', 'max:255'],
            'url' => ['bail', 'required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                if (NavTree::navUrlError((string) $value) !== null) {
                    $fail(NavTree::URL_ERROR);
                }
            }],
            'order' => ['required', 'integer'],
            'isActive' => ['required', 'boolean'],
            'parentId' => ['nullable', 'integer'],
        ];
        if ($withId) {
            $types['id'] = 'int';
            $rules['id'] = ['required', 'integer'];
        }

        return ActionInput::validate($request, $types, $rules, [
            'label.required' => 'The label is required.',
            'label.max' => 'Too big: expected string to have <=255 characters',
            'url.max' => 'Too big: expected string to have <=255 characters',
            'url.required' => 'The URL is required.',
            'order.required' => 'The order must be a number.',
            'order.integer' => 'The order must be a number.',
        ]);
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

    /** zod `z.number().int()` on JSON input: numeric strings are rejected. */
    private static function jsonInt(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value)) {
                $fail('Invalid input: expected number, received '.get_debug_type($value));
            }
        };
    }
}
