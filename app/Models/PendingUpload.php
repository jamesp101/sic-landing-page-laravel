<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingUpload extends Model
{
    protected $table = 'pending_uploads';

    protected $fillable = ['key', 'user_id', 'expires_at'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public $timestamps = false;
}
