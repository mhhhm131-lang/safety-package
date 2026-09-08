<?php

namespace App\Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قناة تحقق من بيانات المقاول (كانت TenantContractorChannel — صف لكل قناة بلا tenant).
 * pdf_upload وportal_link بلا إعداد؛ etimad وgosi تحتاجان api_key/base_url ولا تعمل بلا مفاتيح (لا محاكاة).
 */
class ContractorChannel extends Model
{
    public const CHANNEL_PDF        = 'pdf_upload';
    public const CHANNEL_PORTAL     = 'portal_link';
    public const CHANNEL_ETIMAD     = 'etimad';
    public const CHANNEL_GOSI       = 'gosi';
    public const CHANNEL_MUQAWIL    = 'muqawil';
    public const CHANNEL_API        = 'contractor_api';

    public const ALL_TYPES = [self::CHANNEL_PDF, self::CHANNEL_PORTAL, self::CHANNEL_ETIMAD, self::CHANNEL_GOSI, self::CHANNEL_MUQAWIL, self::CHANNEL_API];

    public const LABELS = [
        self::CHANNEL_PDF => 'رفع مستندات PDF', self::CHANNEL_PORTAL => 'رابط تعبئة للمقاول', self::CHANNEL_ETIMAD => 'منصة اعتماد',
        self::CHANNEL_GOSI => 'التأمينات الاجتماعية', self::CHANNEL_MUQAWIL => 'تصنيف المقاولين', self::CHANNEL_API => 'واجهة برمجية للمقاول',
    ];

    public const CONFIDENCE = [
        self::CHANNEL_PDF => 40, self::CHANNEL_PORTAL => 50, self::CHANNEL_API => 60, self::CHANNEL_MUQAWIL => 70,
        self::CHANNEL_GOSI => 80, self::CHANNEL_ETIMAD => 90, 'manual' => 20,
    ];

    protected $fillable = ['channel_type', 'enabled', 'priority', 'config_json', 'created_by_id'];

    protected $casts = ['enabled' => 'boolean', 'priority' => 'integer', 'config_json' => 'encrypted:array'];

    protected $attributes = ['enabled' => false, 'priority' => 50];

    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_id'); }

    public function isConfigured(): bool
    {
        return match ($this->channel_type) {
            self::CHANNEL_PDF, self::CHANNEL_PORTAL => true,
            default => !empty($this->config_json),
        };
    }

    /** القنوات المفعّلة بترتيب الأولوية، مفهرسة بالنوع. */
    public static function enabledByType(): \Illuminate\Support\Collection
    {
        return static::where('enabled', true)->orderBy('priority')->get()->keyBy('channel_type');
    }

    public static function enabledTypes(): array
    {
        return static::where('enabled', true)->pluck('channel_type')->all();
    }
}
