<?php

namespace App\Modules\Incident\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مرفق البلاغ (صورة من المبلّغ أو دليل المعالجة من الفني). المحتوى base64 في القاعدة:
 * OHSMS يحفظ على القرص عبر Attachment العام (وحدة System غير منقولة) والقرص على Render مؤقت.
 */
class IncidentAttachment extends Model
{
    const UPDATED_AT = null;

    public const MAX_BYTES = 3 * 1024 * 1024;
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    protected $fillable = ['incident_id', 'kind', 'original_name', 'mime', 'size', 'data', 'uploaded_by_id'];

    protected $casts = ['created_at' => 'datetime', 'size' => 'integer'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /** من data URL (صورة مضغوطة في المتصفح) إلى صف. يعيد null إن لم تكن صيغة/حجماً مقبولين. */
    public static function fromDataUrl(?string $dataUrl, string $kind, ?int $userId, ?string $name = null): ?array
    {
        if (!$dataUrl || !preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            return null;
        }
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) === 0 || strlen($bin) > self::MAX_BYTES) {
            return null;
        }
        return ['kind' => $kind, 'original_name' => $name ?: 'photo.jpg', 'mime' => $m[1], 'size' => strlen($bin),
            'data' => $m[2], 'uploaded_by_id' => $userId, 'created_at' => now()];
    }
}
