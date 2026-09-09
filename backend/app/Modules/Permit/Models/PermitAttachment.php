<?php

namespace App\Modules\Permit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;

/** مرفق التصريح — base64 في القاعدة (قرص Render مؤقت، كما مرفقات البلاغات ومستندات المقاولين). */
class PermitAttachment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['permit_id', 'name', 'original_name', 'mime', 'size', 'data', 'uploaded_by_id', 'created_at'];

    protected $hidden = ['data'];

    protected $casts = ['created_at' => 'datetime', 'size' => 'integer'];

    public function permit(): BelongsTo     { return $this->belongsTo(Permit::class); }
    public function uploadedBy(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by_id'); }

    public static function fromUpload(Permit $permit, UploadedFile $file, string $name, ?int $userId): self
    {
        return self::create([
            'permit_id'      => $permit->id,
            'name'           => $name,
            'original_name'  => $file->getClientOriginalName(),
            'mime'           => $file->getMimeType() ?: 'application/octet-stream',
            'size'           => $file->getSize() ?: 0,
            'data'           => base64_encode((string) file_get_contents($file->getRealPath())),
            'uploaded_by_id' => $userId,
            'created_at'     => now(),
        ]);
    }
}
