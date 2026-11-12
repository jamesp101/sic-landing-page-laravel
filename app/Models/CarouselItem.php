<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarouselItem extends Model
{
    protected $table = 'carousel_items';

    protected $fillable = ['title', 'subtitle', 'image_path', 'link_url', 'overlay', 'video_path', 'video_url', 'order', 'department_id', 'show_on_main'];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'department_id' => 'integer',
            'show_on_main' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
