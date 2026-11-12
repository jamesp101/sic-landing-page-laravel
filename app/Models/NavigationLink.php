<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NavigationLink extends Model
{
    protected $table = 'navigation_links';

    protected $fillable = ['label', 'url', 'order', 'is_active', 'parent_id'];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'is_active' => 'boolean',
            'parent_id' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(NavigationLink::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(NavigationLink::class, 'parent_id');
    }
}
