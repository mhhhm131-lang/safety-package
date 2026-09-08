<?php

namespace App\Modules\Risk\Services;

use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;

class RiskCodeService
{
    private array $arabicMap = [
        'أ' => 'A', 'إ' => 'A', 'آ' => 'A', 'ا' => 'A',
        'ب' => 'B', 'ت' => 'T', 'ث' => 'T', 'ج' => 'J',
        'ح' => 'H', 'خ' => 'K', 'د' => 'D', 'ذ' => 'D',
        'ر' => 'R', 'ز' => 'Z', 'س' => 'S', 'ش' => 'S',
        'ص' => 'S', 'ض' => 'D', 'ط' => 'T', 'ظ' => 'T',
        'ع' => 'A', 'غ' => 'G', 'ف' => 'F', 'ق' => 'Q',
        'ك' => 'K', 'ل' => 'L', 'م' => 'M', 'ن' => 'N',
        'ه' => 'H', 'و' => 'W', 'ي' => 'Y', 'ى' => 'Y',
        'ة' => 'H',
    ];

    private array $stopwords = [
        'المخاطر', 'مخاطر', 'خطر', 'ضعف', 'عدم', 'نقص',
        'من', 'في', 'على', 'إلى', 'عن', 'مع', 'بين', 'وجود',
        'و', 'أو', 'ثم', 'لا', 'غير', 'بدون',
    ];

    public function abbreviationFromArabic(string $name): string
    {
        $cleaned = preg_replace('/\bال/u', '', $name);
        $words = array_values(array_filter(
            array_map('trim', explode(' ', $cleaned)),
            fn ($w) => !in_array($w, $this->stopwords, true) && mb_strlen($w, 'UTF-8') > 1
        ));

        if (!$words) {
            return 'RSK';
        }

        // Multiple significant words → first letter of each (up to 3 words)
        if (count($words) >= 2) {
            $abbrev = '';
            foreach ($words as $word) {
                if (mb_strlen($abbrev) >= 3) break;
                $ch = mb_substr($word, 0, 1, 'UTF-8');
                $abbrev .= $this->arabicMap[$ch] ?? 'X';
            }
            return strtoupper($abbrev);
        }

        // Single word → first 3 letters transliterated
        $word   = $words[0];
        $abbrev = '';
        $len    = mb_strlen($word, 'UTF-8');
        for ($i = 0; $i < $len && mb_strlen($abbrev) < 3; $i++) {
            $ch = mb_substr($word, $i, 1, 'UTF-8');
            if (isset($this->arabicMap[$ch])) {
                $abbrev .= $this->arabicMap[$ch];
            }
        }

        return strtoupper($abbrev ?: 'RSK');
    }

    public function generateCategoryAbbreviation(string $name): string
    {
        $base = $this->abbreviationFromArabic($name);
        $abbrev = $base;
        $i = 2;
        while (RiskCategory::where('abbreviation', $abbrev)->exists()) {
            $abbrev = $base . $i++;
        }
        return $abbrev;
    }

    public function generateSubCategoryAbbreviation(string $name): string
    {
        $base = $this->abbreviationFromArabic($name);
        $abbrev = $base;
        $i = 2;
        while (RiskSubCategory::where('abbreviation', $abbrev)->exists()) {
            $abbrev = $base . $i++;
        }
        return $abbrev;
    }

    public function generateRiskCode(int $categoryId, int $subCategoryId): string
    {
        // withoutGlobalScopes() needed — master categories have tenant_id=NULL
        // and would be filtered out by BelongsToTenant scope otherwise.
        $catAbbr = RiskCategory::find($categoryId)?->abbreviation ?? 'RSK';
        $subAbbr = RiskSubCategory::find($subCategoryId)?->abbreviation ?? 'GEN';
        $prefix  = "{$catAbbr}-{$subAbbr}-";

        // Search across ALL registries/tenants for uniqueness.
        $lastCode = Risk::where('code', 'like', "{$prefix}%")
            ->get('code')
            ->map(fn ($r) => (int) last(explode('-', $r->code)))
            ->max();

        $seq  = ($lastCode ?? 0) + 1;
        $code = $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);

        while (Risk::where('code', $code)->exists()) {
            $seq++;
            $code = $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
        }

        return $code;
    }
}
