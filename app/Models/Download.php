<?php

namespace App\Models;

use App\Storage\FileOwnership;
use App\Storage\OwnsFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Download extends Model implements OwnsFiles
{
    protected $table = 'downloads';

    protected $fillable = ['file_name', 'file_path', 'file_size', 'tags', 'directory', 'department_id', 'user_id'];

    public static function fileOwnership(): FileOwnership
    {
        return new FileOwnership(columns: ['file_path' => ['prefix' => 'downloads', 'variants' => false]]);
    }

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'department_id' => 'integer',
            'user_id' => 'integer',
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
