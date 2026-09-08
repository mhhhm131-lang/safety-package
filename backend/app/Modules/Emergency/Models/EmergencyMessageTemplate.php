<?php

namespace App\Modules\Emergency\Models;

use App\Core\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class EmergencyMessageTemplate extends Model
{

    protected $fillable = [
        'code',
        'is_builtin',
        'name',
        'category',
        'title_ar',
        'title_en',
        'message_ar',
        'message_en',
        'variables',
        'default_channels',
        'severity',
        'auto_trigger',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'variables' => 'array',
        'default_channels' => 'array',
        'auto_trigger' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'severity' => 'high',
        'auto_trigger' => false,
        'is_active' => true,
        'sort_order' => 0,
    ];

    // Category constants
    const CATEGORY_FIRE = 'fire';
    const CATEGORY_EARTHQUAKE = 'earthquake';
    const CATEGORY_MEDICAL = 'medical';
    const CATEGORY_SECURITY = 'security';
    const CATEGORY_WEATHER = 'weather';
    const CATEGORY_CHEMICAL = 'chemical';
    const CATEGORY_EVACUATION = 'evacuation';
    const CATEGORY_DRILL = 'drill';
    const CATEGORY_ALL_CLEAR = 'all_clear';
    const CATEGORY_CUSTOM = 'custom';

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeGlobal($query)
    {
        return $query->where('is_builtin', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // Helpers
    public function getCategoryLabel(): string
    {
        return match($this->category) {
            'fire' => 'حريق',
            'earthquake' => 'زلزال',
            'medical' => 'طبي',
            'security' => 'أمني',
            'weather' => 'طقس',
            'chemical' => 'كيميائي',
            'evacuation' => 'إخلاء',
            'drill' => 'تمرين',
            'all_clear' => 'انتهاء الخطر',
            'custom' => 'مخصص',
            default => $this->category,
        };
    }

    public function getCategoryIcon(): string
    {
        return match($this->category) {
            'fire' => 'bi-fire',
            'earthquake' => 'bi-tsunami',
            'medical' => 'bi-heart-pulse',
            'security' => 'bi-shield-exclamation',
            'weather' => 'bi-cloud-lightning',
            'chemical' => 'bi-droplet-half',
            'evacuation' => 'bi-box-arrow-right',
            'drill' => 'bi-clipboard-check',
            'all_clear' => 'bi-check-circle',
            'custom' => 'bi-chat-text',
            default => 'bi-exclamation-triangle',
        };
    }

    public function getCategoryColor(): string
    {
        return match($this->category) {
            'fire' => '#fd7e14',
            'earthquake' => '#6f42c1',
            'medical' => '#e83e8c',
            'security' => '#dc3545',
            'weather' => '#17a2b8',
            'chemical' => '#20c997',
            'evacuation' => '#ffc107',
            'drill' => '#6c757d',
            'all_clear' => '#28a745',
            'custom' => '#007bff',
            default => '#6c757d',
        };
    }

    public function getSeverityLabel(): string
    {
        return match($this->severity) {
            'low' => 'منخفض',
            'medium' => 'متوسط',
            'high' => 'مرتفع',
            'critical' => 'حرج',
            default => $this->severity,
        };
    }

    public function getSeverityColor(): string
    {
        return match($this->severity) {
            'low' => 'success',
            'medium' => 'warning',
            'high' => 'orange',
            'critical' => 'danger',
            default => 'secondary',
        };
    }

    /**
     * Get title in specified language (defaults to Arabic)
     */
    public function getTitle(string $lang = 'ar'): string
    {
        if ($lang === 'en' && $this->title_en) {
            return $this->title_en;
        }
        return $this->title_ar;
    }

    /**
     * Get message in specified language (defaults to Arabic)
     */
    public function getMessage(string $lang = 'ar'): string
    {
        if ($lang === 'en' && $this->message_en) {
            return $this->message_en;
        }
        return $this->message_ar;
    }

    /**
     * Render message with variables replaced
     */
    public function render(array $values, string $lang = 'ar'): array
    {
        $title = $this->getTitle($lang);
        $message = $this->getMessage($lang);

        foreach ($values as $key => $value) {
            $title = str_replace("{{{$key}}}", $value, $title);
            $message = str_replace("{{{$key}}}", $value, $message);
        }

        return [
            'title' => $title,
            'message' => $message,
            'severity' => $this->severity,
            'channels' => $this->default_channels,
        ];
    }

    /**
     * Get available categories
     */
    public static function getCategories(): array
    {
        return [
            self::CATEGORY_FIRE => 'حريق',
            self::CATEGORY_EARTHQUAKE => 'زلزال',
            self::CATEGORY_MEDICAL => 'طبي',
            self::CATEGORY_SECURITY => 'أمني',
            self::CATEGORY_WEATHER => 'طقس',
            self::CATEGORY_CHEMICAL => 'كيميائي',
            self::CATEGORY_EVACUATION => 'إخلاء',
            self::CATEGORY_DRILL => 'تمرين',
            self::CATEGORY_ALL_CLEAR => 'انتهاء الخطر',
            self::CATEGORY_CUSTOM => 'مخصص',
        ];
    }

    /**
     * Find template by code
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }

    /**
     * Get templates for a category
     */
    public static function forCategory(string $category)
    {
        return static::where('category', $category)
            ->active()
            ->ordered()
            ->get();
    }
}
