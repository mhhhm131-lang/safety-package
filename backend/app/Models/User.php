<?php

namespace App\Models;

use App\Core\Permissions\PermissionRegistry;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['username', 'name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /** الدور من الملف (المصدر الواحد). */
    public function role(): string
    {
        return $this->profile?->role ?? 'employee';
    }

    public function roleName(): string
    {
        return PermissionRegistry::getRoleDisplayName($this->role());
    }

    public function isActive(): bool
    {
        return (bool) ($this->profile?->is_active ?? false);
    }

    public function can_(string $permission): bool
    {
        return PermissionRegistry::hasPermission($this->role(), $permission);
    }
}
