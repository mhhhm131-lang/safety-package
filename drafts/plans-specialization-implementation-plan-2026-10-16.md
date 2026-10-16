# خطة التنفيذ — تخصيص الخطط والبطاقات
**التاريخ:** ٢٠٢٦-١٠-١٦  
**الحالة:** جاهزة للموافقة  

---

## الخطة بكلاختصار

| # | الخطوة | المخرج | قياس النجاح |
|---|--------|--------|------------|
| **١** | جدول `plan_specializations` | جدول يحفظ التعديلات المحلية | يمكن إدراج/قراءة صف اختبار |
| **٢** | كود قراءة يختار (تخصيص vs أساسي) | وظيفة تعيد النص الصحيح | test يقارن: موجود تخصيص → عرضه؛ لا → عرض الأساسي |
| **٣** | شاشة تحرير بسيطة | نموذج يحفظ التعديل | يمكن تعديل وحفظ وإعادة قراءة |
| **٤** | اختبار على المنشور | النسخة المحدّثة تظهر | ملز يرى أساسي، القصيم يرى تخصيصه |

---

## الخطوة الأولى بالتفصيل

### ١-١ ماذا نفعل
إنشاء **جدول واحد** فقط:

```sql
CREATE TABLE plan_specializations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    plan_id INT,                    -- معرّف الخطة (١-١٧)
    department_id INT,              -- معرّف الإدارة/الفرع
    item_id INT,                    -- معرّف البند في الخطة
    value_key VARCHAR(100),         -- اسم الحقل (responsible_person, location, إلخ)
    value TEXT,                     -- القيمة الجديدة (اسم الطبيب الجديد، إلخ)
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE(plan_id, department_id, item_id, value_key)
);
```

### ١-٢ المخرج
- ✅ جدول موجود في قاعدة البيانات
- ✅ Migration مُنفَّذة
- ✅ يمكن إدراج صف اختبار يدويّاً والتحقق منه

### ١-٣ قياس النجاح
```sql
-- اختبر: أدرج صف
INSERT INTO plan_specializations (plan_id, department_id, item_id, value_key, value)
VALUES (2, 5, 2, "responsible_person", "إسعاف خارجي 997");

-- اختبر: اقرأ الصف
SELECT * FROM plan_specializations WHERE plan_id=2 AND department_id=5;
-- النتيجة: يظهر الصف مع القيمة الجديدة
```

---

## الخطوة الثانية

### ٢-١ ماذا نفعل
إضافة **وظيفة واحدة** في الـ service:

```php
// app/Services/PlanService.php

public function getItemValue($planId, $departmentId, $itemId, $valueKey, $defaultValue)
{
    // ١. ابحث عن تخصيص
    $specialization = DB::table('plan_specializations')
        ->where('plan_id', $planId)
        ->where('department_id', $departmentId)
        ->where('item_id', $itemId)
        ->where('value_key', $valueKey)
        ->first();
    
    // ٢. إذا وجدت → عودة القيمة الجديدة
    if ($specialization) {
        return $specialization->value;
    }
    
    // ٣. إلا → عودة الأساسي
    return $defaultValue;
}
```

### ٢-٢ المخرج
- ✅ وظيفة تختار بين التخصيص والأساسي
- ✅ تُستخدم في عرض الخطة

### ٢-٣ قياس النجاح
```php
// اختبر
$result = $planService->getItemValue(2, 1, 2, "responsible_person", "الطبيب أحمد");
// الملز (department_id=1): لا تخصيص → "الطبيب أحمد"

$result = $planService->getItemValue(2, 5, 2, "responsible_person", "الطبيب أحمد");
// القصيم (department_id=5): تخصيص موجود → "إسعاف خارجي 997"
```

---

## الخطوة الثالثة

### ٣-١ ماذا نفعل
**نموذج تحرير بسيط** على الصفحة الموجودة:

```html
<!-- في صفحة عرض الخطة، أمام كل بند -->
<button @click="editItem(itemId)">تعديل</button>

<!-- نموذج بسيط -->
<div v-if="editing[itemId]">
    <input v-model="editValue" placeholder="القيمة الجديدة">
    <button @click="save(itemId, editValue)">حفظ</button>
</div>
```

**API واحد:**
```php
// POST /api/plans/specializations
{
    "plan_id": 2,
    "department_id": 5,
    "item_id": 2,
    "value_key": "responsible_person",
    "value": "إسعاف خارجي 997"
}
```

### ٣-٢ المخرج
- ✅ يمكن تعديل البند مباشرة
- ✅ يُحفظ في الجدول
- ✅ عند إعادة التحميل، يظهر التعديل

### ٣-٣ قياس النجاح
- مسؤول سلامة الملز يفتح الخطة → يرى الأساسي ✓
- مدير القصيم يفتح الخطة → يرى التعديل ✓
- يعدّل قيمة → يحفظ ✓
- يحدّث الصفحة → يرى التعديل الجديد ✓

---

## الخطوة الرابعة

### ٤-١ ماذا نفعل
**اختبار على المنشور:**
- الملز يفتح خطة → يرى النسخة الأساسية
- القصيم يفتح خطة → يرى نسختهم المعدّلة
- يعدّل → يحفظ → يرى التغيير

### ٤-٢ المخرج
- ✅ النظام يعمل كما هو مطلوب

---

## ملاحظات مهمة

### ما الذي **لا** نعدّل
- ✗ وثائق المعهد (cover, policy, الخطط الأصلية)
- ✗ الواجهة الحالية (فقط نضيف زر تعديل)
- ✗ نماذج الفحص (تبقى كما هي)

### ما الذي **يبقى** ثابتاً
- ✓ البنية (عدد البنود، العناوين)
- ✓ الصلاحيات (مدير الإدارة يعدّل فرعه فقط)
- ✓ الكود الموجود (نضيف فقط، لا نعدّل)

---

## الآن السؤال

### هل هذه الخطة واضحة؟

```
☐ نعم، ابدأ بالخطوة ١ (الجدول)
☐ عندي سؤال عن الخطة
☐ عندي تعديل على الخطة
```

**بعد الموافقة مباشرة → نبدأ الكود.**
