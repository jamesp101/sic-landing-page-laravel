<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    protected $table = 'posts';

    protected $fillable = ['title', 'slug', 'content', 'search_text', 'type', 'start_date', 'end_date', 'highlight_image_path', 'department_id', 'user_id', 'is_featured', 'hide_author'];

    protected function casts(): array
    {
        return [
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'department_id' => 'integer',
            'user_id' => 'integer',
            'is_featured' => 'boolean',
            'hide_author' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
