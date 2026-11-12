<?php

namespace App\Actions;

use App\Admin\ActionInput;
use App\Admin\DepartmentAccess;
use App\Admin\Slug;
use App\Content\Content;
use App\Content\ContentField;
use App\Models\Post;
use App\Storage\ImageFile;
use App\Storage\Storage;
use App\Storage\StoredFiles;
use App\Support\Authz;
use App\Support\Flash;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/** `posts.*` actions (multipart for create/update, JSON for delete). */
class PostsActions
{
    private const POST_TYPES = ['article', 'event', 'announcement'];

    private const TYPES = [
        'title' => 'string', 'slug' => 'string', 'content' => 'text', 'type' => 'string',
        'startDate' => 'string', 'endDate' => 'string', 'departmentId' => 'int',
        'isFeatured' => 'bool', 'hideAuthor' => 'bool', 'highlightImage' => 'raw',
    ];

    public function create(Request $request): array
    {
        $input = $this->input($request, false);
        $actor = Authz::requireUser($request->user());
        $departmentId = DepartmentAccess::resolveWriteDepartment($actor, $input['departmentId'] ?? null);
        $slug = $this->resolveSlug($input);

        $post = StoredFiles::commitWithFiles([
            'files' => ['highlight' => $this->highlight($input)],
            'run' => fn (array $keys) => Post::query()->create([
                'title' => $input['title'],
                'slug' => $slug,
                'content' => $input['content'],
                'search_text' => Content::searchTextOf($input['content']),
                'type' => $input['type'],
                'start_date' => $input['startDate'] ?? null,
                'end_date' => $input['endDate'] ?? null,
                'highlight_image_path' => $keys['highlight'] ?? null,
                'department_id' => $departmentId,
                'user_id' => $actor->id,
                'is_featured' => $input['isFeatured'],
                'hide_author' => $input['hideAuthor'],
            ]),
        ]);
        Flash::success('Post created successfully.');

        return ['id' => $post->id];
    }

    public function update(Request $request): array
    {
        $input = $this->input($request, true);
        $actor = Authz::requireUser($request->user());
        /** @var Post $post */
        $post = DepartmentAccess::loadScoped($actor, 'post', $input['id']);
        $departmentId = DepartmentAccess::resolveWriteDepartment($actor, $input['departmentId'] ?? null);
        $slug = $this->resolveSlug($input, $post->id);
        $highlight = $this->highlight($input);

        StoredFiles::commitWithFiles([
            'files' => ['highlight' => $highlight],
            'run' => function (array $keys) use ($post, $input, $slug, $departmentId) {
                $data = [
                    'title' => $input['title'],
                    'slug' => $slug,
                    'content' => $input['content'],
                    'search_text' => Content::searchTextOf($input['content']),
                    'type' => $input['type'],
                    'start_date' => $input['startDate'] ?? null,
                    'end_date' => $input['endDate'] ?? null,
                    'department_id' => $departmentId,
                    'is_featured' => $input['isFeatured'],
                    'hide_author' => $input['hideAuthor'],
                ];
                if (($keys['highlight'] ?? null) !== null) {
                    $data['highlight_image_path'] = $keys['highlight'];
                }

                return $post->update($data);
            },
            'stale' => [$highlight ? $post->highlight_image_path : null],
        ]);
        Flash::success('Post updated successfully.');

        return ['id' => $post->id];
    }

    public function delete(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => 'int'], ['id' => ['required', 'integer']]);
        $actor = Authz::requireUser($request->user());
        /** @var Post $post */
        $post = DepartmentAccess::loadScoped($actor, 'post', $input['id']);
        StoredFiles::deleteWithFiles(fn () => $post->delete(), [$post->highlight_image_path]);
        Flash::success('Post deleted successfully.');

        return ['id' => $input['id']];
    }

    /** Validates the form; authorization runs after the shape check, like the TS action schema. */
    private function input(Request $request, bool $withId): array
    {
        $date = fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && strtotime((string) $value) === false) {
                $fail('Enter a valid date.');
            }
        };
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'content' => ContentField::rules(true),
            'type' => ['required', Rule::in(self::POST_TYPES)],
            'startDate' => ['nullable', 'string', $date()],
            'endDate' => ['nullable', 'string', $date(), function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                $start = $request->input('startDate');
                $end = $value ? strtotime((string) $value) : false;
                $begin = $start ? strtotime((string) $start) : false;
                if ($end !== false && $begin !== false && $end < $begin) {
                    $fail('The end date must be on or after the start date.');
                }
            }],
            'departmentId' => ['nullable', 'integer'],
            'isFeatured' => ['required', 'boolean'],
            'hideAuthor' => ['required', 'boolean'],
            'highlightImage' => ['nullable', ...ImageFile::imageFile(Storage::limit('image'), 'highlight')],
        ];
        $types = self::TYPES;
        if ($withId) {
            $types['id'] = 'int';
            $rules['id'] = ['required', 'integer'];
        }

        $input = ActionInput::validate($request, $types, $rules, [
            'title.required' => 'The title is required.',
            'content.required' => 'The content is required.',
            'type.required' => 'Choose a valid post type.',
            'type.in' => 'Choose a valid post type.',
        ]);
        // Block documents are stored in normalised form; Markdown passes through unchanged.
        $input['content'] = Content::checkContent($input['content'])['value'];

        return $input;
    }

    /** @return array{prefix: string, file: mixed, variant: true}|null */
    private function highlight(array $input): ?array
    {
        $file = $input['highlightImage'] ?? null;

        return $file instanceof UploadedFile
            ? ['prefix' => 'posts', 'file' => ImageFile::upload($file, 'highlight'), 'variant' => true]
            : null;
    }

    /** `slugify(slug ?? title)`; empty becomes `post`; `-1`, `-2`, … until unique (excluding the post itself). */
    private function resolveSlug(array $input, ?int $excludeId = null): string
    {
        return Slug::uniqueSlug(
            isset($input['slug']) && $input['slug'] !== '' ? $input['slug'] : $input['title'],
            fn (string $slug) => Post::query()
                ->where('slug', $slug)
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists(),
        );
    }
}
