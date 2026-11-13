<?php

namespace App\Models;

use App\Carousel\CarouselAdmin;
use App\Storage\FileOwnership;
use App\Storage\OwnsFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarouselItem extends Model implements OwnsFiles
{
    protected $table = 'carousel_items';

    protected $fillable = ['title', 'subtitle', 'image_path', 'link_url', 'overlay', 'video_path', 'video_url', 'order', 'department_id', 'show_on_main'];

    /** The photo and video are the Slide's; so are the Layer images in its Overlay while no other Slide uses them. */
    public static function fileOwnership(): FileOwnership
    {
        return new FileOwnership(
            columns: [
                'image_path' => ['prefix' => 'carousels', 'variants' => true],
                'video_path' => ['prefix' => 'videos', 'variants' => false],
            ],
            embedded: ['overlay' => ['keys' => CarouselAdmin::overlayImageSrcs(...), 'unused' => CarouselAdmin::unusedLayerImageKeys(...)]],
        );
    }

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
