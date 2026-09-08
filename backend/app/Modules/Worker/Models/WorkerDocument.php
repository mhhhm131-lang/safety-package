<?php

namespace App\Modules\Worker\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class WorkerDocument extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'worker_id',
        'document_type',
        'name',
        'file',
        'file_mime',
        'file_data',
        'expiry_date',
        'notes',
        'created_at',
        'uploaded_by_id',
    ];

    protected $hidden = ['file_data'];

    protected $casts = [
        'expiry_date' => 'date',
        'created_at' => 'datetime',
    ];

    // Relationships

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    // Accessors

    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date && $this->expiry_date->lt(Carbon::today());
    }

    public function getIsExpiringSoonAttribute(): bool
    {
        return $this->expiry_date
            && $this->expiry_date->gte(Carbon::today())
            && $this->expiry_date->lte(Carbon::today()->addDays(30));
    }

    /** الملف base64 في القاعدة (قرص Render مؤقت — كما مرفقات البلاغات). الحد ٥ ميجابايت من الشاشة. */
    public function attachUpload(\Illuminate\Http\UploadedFile $file): void
    {
        $this->file = $file->getClientOriginalName();
        $this->file_mime = $file->getMimeType();
        $content = (string) file_get_contents($file->getRealPath());
        $this->file_data = $content === '' ? null : base64_encode($content);
    }

    public function hasFile(): bool
    {
        return !empty($this->file_data);
    }
}
