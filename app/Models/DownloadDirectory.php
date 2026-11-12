<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DownloadDirectory extends Model
{
    protected $table = 'download_directories';

    protected $fillable = ['name', 'department_id'];

    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
