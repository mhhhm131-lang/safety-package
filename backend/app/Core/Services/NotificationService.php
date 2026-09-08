<?php

namespace App\Core\Services;

use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\UserProfile;

/**
 * صندوق الوارد داخل النظام (منقول من OHSMS بلا tenant).
 * البريد يُضاف فوقه في مراحل الوحدات (قرار BACKEND.md §٦).
 */
class NotificationService
{
    public function create(int $userId, string $type, string $title, ?string $message = null, ?string $url = null): AppNotification
    {
        return AppNotification::create([
            'user_id' => $userId, 'type' => $type, 'title' => $title, 'message' => $message, 'url' => $url,
            'created_at' => now(),
        ]);
    }

    /** إشعار كل أصحاب الأدوار المذكورة (المفعّلين). */
    public function notifyRoles(array $roles, string $type, string $title, ?string $message = null, ?string $url = null): int
    {
        $userIds = UserProfile::whereIn('role', $roles)->where('is_active', true)->pluck('user_id')->unique();
        foreach ($userIds as $userId) {
            $this->create($userId, $type, $title, $message, $url);
        }
        return $userIds->count();
    }

    public function markRead(int $notificationId, int $userId): bool
    {
        $n = AppNotification::where('id', $notificationId)->where('user_id', $userId)->first();
        if (!$n) return false;
        $n->is_read = true;
        return $n->save();
    }

    public function markAllRead(int $userId): int
    {
        return AppNotification::where('user_id', $userId)->where('is_read', false)->update(['is_read' => true]);
    }

    public function getUnreadCount(int $userId): int
    {
        return AppNotification::where('user_id', $userId)->where('is_read', false)->count();
    }
}
