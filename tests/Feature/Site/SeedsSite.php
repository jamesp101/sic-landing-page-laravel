<?php

namespace Tests\Feature\Site;

use App\Models\CarouselItem;
use App\Models\Department;
use App\Models\Download;
use App\Models\HeroSection;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Row builders for the public-site tests. */
trait SeedsSite
{
    protected User $owner;

    protected function seedOwner(): User
    {
        return $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test']);
    }

    protected function day(int $n): CarbonImmutable
    {
        return new CarbonImmutable(sprintf('2026-01-%02dT00:00:00Z', $n));
    }

    protected function makeDepartment(string $name, string $slug, array $over = []): Department
    {
        return Department::query()->forceCreate(['name' => $name, 'slug' => $slug, ...$over]);
    }

    protected function makePost(string $slug, array $over = []): Post
    {
        $createdAt = $over['created_at'] ?? $this->day(1);
        unset($over['created_at']);

        return Post::query()->forceCreate([
            'title' => $slug, 'slug' => $slug, 'type' => 'article', 'user_id' => $this->owner->id,
            'content' => '', 'search_text' => $slug, 'created_at' => $createdAt, 'updated_at' => $createdAt,
            ...$over,
        ]);
    }

    protected function makeSlide(string $imagePath, array $over = []): CarouselItem
    {
        return CarouselItem::query()->create(['image_path' => $imagePath, ...$over]);
    }

    protected function makeHero(string $title, array $over = []): HeroSection
    {
        return HeroSection::query()->create(['title' => $title, 'content' => $title, 'search_text' => $title, ...$over]);
    }

    protected function makeDownload(string $name, ?string $directory, ?int $departmentId, string $tags = '[]'): Download
    {
        return Download::query()->create([
            'file_name' => $name, 'file_path' => "downloads/{$name}.pdf", 'file_size' => 100, 'tags' => $tags,
            'directory' => $directory, 'department_id' => $departmentId, 'user_id' => $this->owner->id,
        ]);
    }
}
