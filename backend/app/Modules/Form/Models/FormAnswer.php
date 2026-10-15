<?php

namespace App\Modules\Form\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;

/**
 * ردّ على حقل واحد. القيمة نص، والاختيار المتعدد JSON.
 * التوقيع والصورة يُحفظان base64 في القاعدة (قرص Render مؤقت — كما البلاغات والتصاريح).
 * ٢٩-١: الجدول صفوف JSON (`[[خلية، خلية]، …]`)، وبند الإقرار `{ok, und, app, note}`.
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

    /** صفوف الجدول (٢٩-١) — مصفوفة فارغة إن لم تكن الإجابة جدولاً. @return array<int, array<int, string>> */
    public function tableRows(): array
    {
        $v = (string) $this->value;
        if ($v === '' || !str_starts_with($v, '[')) return [];
        $rows = json_decode($v, true);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) return []; // قائمة نصوص (اختيار متعدد) لا جدول
            $out[] = array_map(fn ($c) => (string) $c, array_values($r));
        }

        return $out;
    }

    /** إجابة بند الإقرار الثلاثي (٢٩-١) — مصفوفة فارغة إن لم تكن الإجابة بنداً. @return array{ok?: string, und?: string, app?: string, note?: string} */
    public function ackValue(): array
    {
        $v = (string) $this->value;
        if ($v === '' || !str_starts_with($v, '{')) return [];
        $a = json_decode($v, true);
        if (!is_array($a) || !array_intersect_key($a, FormField::ACK_QUESTIONS)) return [];
        $out = [];
        foreach (array_keys(FormField::ACK_QUESTIONS) as $k) $out[$k] = (string) ($a[$k] ?? '');
        $out['note'] = (string) ($a['note'] ?? '');

        return $out;
    }

    /** إجابة الادعاء (قرار ٨٨) — مصفوفة فارغة إن لم تكن الإجابة ادعاءً. @return array{claim?: string, evidence?: string} */
    public function claimValue(): array
    {
        $v = (string) $this->value;
        if ($v === '' || !str_starts_with($v, '{')) return [];
        $a = json_decode($v, true);
        if (!is_array($a) || !array_key_exists('claim', $a)) return [];

        return ['claim' => (string) ($a['claim'] ?? ''), 'evidence' => (string) ($a['evidence'] ?? '')];
    }

    /** نص الرد كما يُعرض في النتائج والتصدير. */
    public function display(): string
    {
        if ($c = $this->claimValue()) {
            if ($c['claim'] === FormField::CLAIM_NO) return 'لا';
            if ($c['claim'] === FormField::CLAIM_YES) {
                $parts = array_filter([$c['evidence'], $this->hasFile() ? '(ملف مرفق: '.($this->file_name ?: 'وثيقة').')' : '']);
                return 'نعم'.($parts ? ' — '.implode(' ', $parts) : '');
            }
            return '—';
        }
        if ($this->hasFile()) {
            return $this->field?->field_type === FormField::TYPE_SIGNATURE ? '(موقَّع)' : '(ملف مرفق)';
        }
        if ($ack = $this->ackValue()) {
            $parts = [];
            foreach (FormField::ACK_QUESTIONS as $k => $q) {
                $parts[] = rtrim($q, '؟').': '.($ack[$k] !== '' ? $ack[$k] : '—');
            }
            $s = implode(' · ', $parts);

            return $ack['note'] !== '' ? $s.' — الواقع: '.$ack['note'] : $s;
        }
        if ($rows = $this->tableRows()) {
            return implode(' ؛ ', array_map(fn ($r) => implode(' | ', $r), $rows));
        }
        $value = (string) $this->value;
        if ($value !== '' && str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return implode('، ', array_map('strval', $decoded));
            }
        }

        return $value;
    }
}
