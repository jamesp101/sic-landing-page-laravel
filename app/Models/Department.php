<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $table = 'departments';

    protected $fillable = ['name', 'slug', 'description', 'hero_content', 'logo_path'];

    protected function casts(): array
    {
        return [
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function carouselItems(): HasMany
    {
        return $this->hasMany(CarouselItem::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(Download::class);
    }

    public function directories(): HasMany
    {
        return $this->hasMany(DownloadDirectory::class);
    }

    public function heroSections(): HasMany
    {
        return $this->hasMany(HeroSection::class);
    }
}
