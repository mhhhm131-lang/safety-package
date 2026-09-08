<?php

namespace App\Core\Services;

use App\Models\User;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Support\Facades\Mail;

/**
 * صندوق الوارد داخل النظام (منقول من OHSMS بلا tenant).
 * البريد يُضاف فوقه في مراحل الوحدات (قرار BACKEND.md §٦).
 */
class NotificationService
{
    public function create(int $userId, string $type, string $title, ?string $message = null, ?string $url = null): AppNotification
    {
        $n = AppNotification::create([
            'user_id' => $userId, 'type' => $type, 'title' => $title, 'message' => $message, 'url' => $url,
            'created_at' => now(),
        ]);
        $this->mail($userId, $title, $message, $url);
        return $n;
    }

    /**
     * القناة الثانية (BACKEND.md §٦): بريد لكل إشعار إن كان للمستخدم بريد. محاولة لا توقف العمل إن تعطّل المرسل.
     * MAIL_MAILER=log محلياً؛ على الخادم يُضبط SMTP في متغيرات البيئة (الفجوة ٧).
     */
    private function mail(int $userId, string $title, ?string $message, ?string $url): void
    {
        try {
            $email = User::where('id', $userId)->value('email');
            if (!$email) return;
            $body = $title."\n\n".($message ?? '')."\n\n".($url ? url($url) : '')."\n\n— منظومة السلامة والصحة المهنية، معهد الإدارة العامة";
            Mail::raw($body, function ($m) use ($email, $title) {
                $m->to($email)->subject('[السلامة] '.$title);
            });
        } catch (\Throwable $e) {
            report($e);
        }
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
