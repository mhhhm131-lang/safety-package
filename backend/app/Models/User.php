<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * الحسابات في المرحلة ٠: اسم دخول ودور (مفاتيح الأدوار كما في الواجهة الحالية:
     * tech, fm, adm, exec, safety, cons, dept) ورمز الإدارة لمدير الإدارة.
     */
    protected $fillable = [
        'username',
        'name',
        'email',
        'password',
        'role',
        'dept_code',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
