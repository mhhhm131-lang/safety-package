<?php

namespace App\Modules\Project\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalPartyDocument extends Model
{

    const UPDATED_AT = null;

    protected $fillable = [
        'external_party_id',
        'name',
        'document_type',
        'file',
        'file_mime',
        'file_data',
        'expiry_date',
        'notes',
        'created_at',
        'uploaded_by_id',
        'source_channel',
        'is_verified',
        'verified_at',
        'verified_by_id',
    ];

    protected $hidden = ['file_data'];

    protected $casts = [
        'expiry_date'  => 'date',
        'is_verified'  => 'boolean',
        'verified_at'  => 'datetime',
    ];

    // Accessors

    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    public function getIsExpiringSoonAttribute(): bool
    {
        return $this->expiry_date !== null
            && !$this->expiry_date->isPast()
            && $this->expiry_date->lte(Carbon::now()->addDays(30));
    }

    // Relationships

    public function externalParty(): BelongsTo
    {
        return $this->belongsTo(ExternalParty::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
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
