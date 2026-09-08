<?php

namespace App\Modules\Risk\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskCause;
use App\Modules\Risk\Models\RiskSubCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** إضافة فئة فرعية أو نوع خطر (المستوى الثالث) من داخل نماذج الإنشاء (AJAX). */
class RiskTaxonomyController extends Controller
{
    public function storeSubcategory(Request $request)
    {
        $request->validate([
            'category_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
        ]);
        if (!RiskCategory::where('id', $request->integer('category_id'))->exists()) {
            throw ValidationException::withMessages(['category_id' => 'الفئة الرئيسية غير موجودة.']);
        }
        $sub = RiskSubCategory::create([
            'category_id' => $request->integer('category_id'),
            'name' => $request->input('name'), 'name_en' => $request->input('name_en'), 'is_universal' => false,
        ]);
        return response()->json(['id' => $sub->id, 'name' => $sub->name]);
    }

    public function storeCause(Request $request)
    {
        $request->validate([
            'type_category_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
        ]);
        if (!RiskSubCategory::where('id', $request->integer('type_category_id'))->exists()) {
            throw ValidationException::withMessages(['type_category_id' => 'الفئة الفرعية غير موجودة.']);
        }
        $cause = RiskCause::create([
            'type_category_id' => $request->integer('type_category_id'),
            'name' => $request->input('name'), 'name_en' => $request->input('name_en'),
        ]);
        return response()->json(['id' => $cause->id, 'name' => $cause->name]);
    }
}
