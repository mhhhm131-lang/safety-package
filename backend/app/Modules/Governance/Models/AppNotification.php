<?php

namespace App\Modules\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** صندوق الوارد داخل النظام (Notification في OHSMS؛ الجدول app_notifications لتفادي تعارض Laravel). */
class AppNotification extends Model
{
    const UPDATED_AT = null;

    protected $table = 'app_notifications';

    protected $fillable = ['user_id', 'type', 'title', 'message', 'url', 'is_read'];

    protected $casts = ['is_read' => 'boolean'];

    protected $attributes = ['is_read' => false];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
