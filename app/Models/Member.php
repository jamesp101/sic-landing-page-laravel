<?php

namespace App\Models;

use App\Storage\FileOwnership;
use App\Storage\OwnsFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model implements OwnsFiles
{
    protected $table = 'members';

    protected $fillable = ['name', 'role', 'department_id', 'parent_id', 'order', 'image_path'];

    public static function fileOwnership(): FileOwnership
    {
        return new FileOwnership(columns: ['image_path' => ['prefix' => 'members', 'variants' => true]]);
    }

    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
            'parent_id' => 'integer',
            'order' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Member::class, 'parent_id');
    }
}
