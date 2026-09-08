<?php

namespace App\Modules\Emergency\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجل ما أُرسل أثناء الحالة الطارئة (من OHSMS بلا tenant). القناتان الفعليتان: داخل النظام وبريد (قرار §٦).
 * phone_call = نداء هاتفي يدوي لعضو فريق أو جهة اتصال بلا حساب (يُسجَّل «manual» ليُنادى من المركز — لا SMS).
 */
class EmergencyNotification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'incident_id', 'channel', 'recipient_type', 'recipient_id', 'recipient_name', 'recipient_contact',
        'subject', 'body', 'status', 'sent_at', 'delivered_at', 'error_message',
    ];

    protected $casts = ['sent_at' => 'datetime', 'delivered_at' => 'datetime', 'created_at' => 'datetime'];

    protected $attributes = ['status' => 'pending'];

    const CHANNEL_IN_APP = 'in_app';
    const CHANNEL_EMAIL = 'email';
    const CHANNEL_PHONE = 'phone_call';

    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_FAILED = 'failed';
    const STATUS_MANUAL = 'manual';

    protected static function booted(): void
    {
        static::creating(function (self $n) {
            if (empty($n->created_at)) $n->created_at = now();
        });
    }

    public function incident(): BelongsTo { return $this->belongsTo(EmergencyIncident::class, 'incident_id'); }

    public function scopePending($query) { return $query->where('status', self::STATUS_PENDING); }
    public function scopeFailed($query) { return $query->where('status', self::STATUS_FAILED); }
    public function scopeManual($query) { return $query->where('status', self::STATUS_MANUAL); }

    public function markAsSent(): void { $this->update(['status' => self::STATUS_SENT, 'sent_at' => now()]); }
    public function markAsDelivered(): void { $this->update(['status' => self::STATUS_DELIVERED, 'delivered_at' => now()]); }
    public function markAsFailed(string $error): void { $this->update(['status' => self::STATUS_FAILED, 'error_message' => $error]); }

    public function getChannelLabel(): string
    {
        return match ($this->channel) {
            'in_app' => 'داخل النظام', 'email' => 'بريد', 'phone_call' => 'نداء هاتفي', 'push' => 'إشعار جوال',
            'sms' => 'رسالة نصية', 'whatsapp' => 'واتساب', default => $this->channel,
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'قيد الانتظار', 'sent' => 'أُرسل', 'delivered' => 'وصل', 'failed' => 'فشل', 'manual' => 'يُنادى يدوياً',
            default => $this->status,
        };
    }
}
