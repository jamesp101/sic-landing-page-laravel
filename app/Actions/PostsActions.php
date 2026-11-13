<?php

namespace App\Actions;

use App\Admin\ActionField;
use App\Admin\ActionInput;
use App\Admin\DepartmentAccess;
use App\Admin\Slug;
use App\Content\Content;
use App\Content\ContentField;
use App\Models\Post;
use App\Storage\ImageFile;
use App\Storage\ImageUpload;
use App\Storage\Storage;
use App\Storage\StoredFiles;
use App\Support\Flash;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/** `posts.*` actions (multipart for create/update, JSON for delete). */
class PostsActions
{
    private const POST_TYPES = ['article', 'event', 'announcement'];

    #[Action(Access::User)]
    public function create(Request $request): array
    {
        $input = $this->input($request, false);
        $actor = $request->user();
        $departmentId = DepartmentAccess::resolveWriteDepartment($actor, $input['departmentId'] ?? null);
        $slug = $this->resolveSlug($input);

        $post = StoredFiles::save(
            new Post,
            $this->row($input, $departmentId, $slug) + ['user_id' => $actor->id],
            ['highlight_image_path' => $this->highlight($input)],
        );
        Flash::success('Post created successfully.');

        return ['id' => $post->id];
    }

    #[Action(Access::User)]
    public function update(Request $request): array
    {
        $input = $this->input($request, true);
        $actor = $request->user();
        /** @var Post $post */
        $post = DepartmentAccess::loadScoped($actor, Post::class, $input['id']);
        $departmentId = DepartmentAccess::resolveWriteDepartment($actor, $input['departmentId'] ?? null);
        $slug = $this->resolveSlug($input, $post->id);

        StoredFiles::save($post, $this->row($input, $departmentId, $slug), ['highlight_image_path' => $this->highlight($input)]);
        Flash::success('Post updated successfully.');

        return ['id' => $post->id];
    }

    #[Action(Access::User)]
    public function delete(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => ActionField::id()]);
        $actor = $request->user();
        /** @var Post $post */
        $post = DepartmentAccess::loadScoped($actor, Post::class, $input['id']);
        StoredFiles::delete($post);
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

        return ActionInput::validate($request, [
            'title' => ActionField::string(['required', 'string', 'max:255'], ['required' => 'The title is required.']),
            'slug' => ActionField::string(['nullable', 'string', 'max:255']),
            'content' => ActionField::text(ContentField::rules(true), ['required' => 'The content is required.']),
            'type' => ActionField::string(['required', Rule::in(self::POST_TYPES)], ['required' => 'Choose a valid post type.', 'in' => 'Choose a valid post type.']),
            'startDate' => ActionField::string(['nullable', 'string', $date()]),
            'endDate' => ActionField::string(['nullable', 'string', $date(), function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                $start = $request->input('startDate');
                $end = $value ? strtotime((string) $value) : false;
                $begin = $start ? strtotime((string) $start) : false;
                if ($end !== false && $begin !== false && $end < $begin) {
                    $fail('The end date must be on or after the start date.');
                }
            }]),
            'departmentId' => ActionField::int(['nullable', 'integer']),
            'isFeatured' => ActionField::bool(['required', 'boolean']),
            'hideAuthor' => ActionField::bool(['required', 'boolean']),
            'highlightImage' => ActionField::raw(['nullable', ...ImageFile::imageFile(Storage::limit('image'), 'highlight')]),
        ] + ($withId ? ['id' => ActionField::id()] : []));
    }

    /** The Post columns of validated input, shared by create and update (the Highlight image goes through `highlight`). */
    private function row(array $input, ?int $departmentId, string $slug): array
    {
        $content = Content::forStorage($input['content']);

        return [
            'title' => $input['title'],
            'slug' => $slug,
            'content' => $content->value,
            'search_text' => $content->searchText,
            'type' => $input['type'],
            'start_date' => $input['startDate'] ?? null,
            'end_date' => $input['endDate'] ?? null,
            'department_id' => $departmentId,
            'is_featured' => $input['isFeatured'],
            'hide_author' => $input['hideAuthor'],
        ];
    }

    /** The replacement Highlight image, or null to keep the current one. */
    private function highlight(array $input): ?ImageUpload
    {
        $file = $input['highlightImage'] ?? null;

        return $file instanceof UploadedFile ? ImageFile::upload($file, 'highlight') : null;
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
