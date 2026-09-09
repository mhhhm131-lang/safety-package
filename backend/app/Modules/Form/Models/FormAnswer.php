<?php

namespace App\Modules\Form\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;

/**
 * ردّ على حقل واحد. القيمة نص، والاختيار المتعدد JSON.
 * التوقيع والصورة يُحفظان base64 في القاعدة (قرص Render مؤقت — كما البلاغات والتصاريح).
 */
class FormAnswer extends Model
{
    public $timestamps = false;

    protected $fillable = ['submission_id', 'field_id', 'value', 'file_name', 'file_mime', 'file_data'];

    protected $hidden = ['file_data'];

    public function submission(): BelongsTo { return $this->belongsTo(FormSubmission::class, 'submission_id'); }
    public function field(): BelongsTo      { return $this->belongsTo(FormField::class, 'field_id'); }

    public function hasFile(): bool
    {
        return !empty($this->file_data);
    }

    /** ملف مرفوع (صورة). */
    public function attachUpload(UploadedFile $file): void
    {
        $this->file_name = $file->getClientOriginalName();
        $this->file_mime = $file->getMimeType() ?: 'application/octet-stream';
        $content = (string) file_get_contents($file->getRealPath());
        $this->file_data = $content === '' ? null : base64_encode($content);
    }

    /**
     * توقيع مرسوم يصل من المتصفح كـ data URL (`data:image/png;base64,...`).
     * يُرفض ما ليس صورة، ويُحدّ حجمه حتى لا يُملأ الصف بمدخل عابث.
     */
    public function attachSignatureDataUrl(string $dataUrl, int $maxBytes = 512000): bool
    {
        if (!preg_match('#^data:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=\s]+)$#', trim($dataUrl), $m)) {
            return false;
        }
        $binary = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
        if ($binary === false || $binary === '' || strlen($binary) > $maxBytes) {
            return false;
        }

        $this->file_name = 'signature.png';
        $this->file_mime = $m[1];
        $this->file_data = base64_encode($binary);

        return true;
    }

    /** نص الرد كما يُعرض في النتائج والتصدير. */
    public function display(): string
    {
        if ($this->hasFile()) {
            return $this->field?->field_type === FormField::TYPE_SIGNATURE ? '(موقَّع)' : '(ملف مرفق)';
        }
        $value = (string) $this->value;
        if ($value !== '' && str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return implode('، ', $decoded);
            }
        }

        return $value;
    }
}
