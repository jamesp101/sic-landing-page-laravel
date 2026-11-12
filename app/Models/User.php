<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person who signs in to the admin. `role` is `superadmin` or `department_user` (see App\Support\Authz).
 * The Password login is the bcrypt hash in `password`; write it only through App\Auth\PasswordLogin.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_SUPERADMIN = 'superadmin';

    public const ROLE_DEPARTMENT_USER = 'department_user';

    protected $fillable = ['name', 'email', 'email_verified', 'email_verified_at', 'image', 'role', 'department_id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified' => 'boolean',
            'email_verified_at' => 'datetime',
            'department_id' => 'integer',
            'password' => 'hashed',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPERADMIN;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(Download::class);
    }
}
