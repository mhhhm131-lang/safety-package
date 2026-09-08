@extends('layouts.app')

@section('title', 'ملفي الطبي')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('emergency.medical.dashboard') }}">الملفات الطبية</a></li>
                    <li class="breadcrumb-item active">ملفي الطبي</li>
                </ol>
            </nav>
            <h1 class="h3 mb-0">
                <i class="bi bi-heart-pulse me-2"></i>ملفي الطبي للطوارئ
            </h1>
        </div>
        <div>
            @if($profile->is_verified)
                <span class="badge bg-success fs-6">
                    <i class="bi bi-check-circle me-1"></i>محقق
                </span>
            @else
                <span class="badge bg-warning fs-6">
                    <i class="bi bi-exclamation-triangle me-1"></i>في انتظار التحقق
                </span>
            @endif
        </div>
    </div>

    <div class="alert alert-info mb-4">
        <i class="bi bi-shield-lock me-2"></i>
        <strong>خصوصيتك مهمة:</strong> هذه المعلومات تُستخدم فقط في حالات الطوارئ لضمان سلامتك.
        يمكنك التحكم في من يستطيع الوصول إليها من خلال إعدادات المشاركة.
    </div>

    <form id="medicalProfileForm">
        <div class="row g-4">
            <!-- Basic Medical Info -->
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-file-medical me-2"></i>المعلومات الطبية الأساسية</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">فصيلة الدم</label>
                                <select name="blood_type" class="form-select">
                                    <option value="">غير محدد</option>
                                    @foreach(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $type)
                                        <option value="{{ $type }}" {{ $profile->blood_type === $type ? 'selected' : '' }}>
                                            {{ $type }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">الحساسية (allergies)</label>
                            <div class="row g-2">
                                @foreach(\App\Modules\Emergency\Models\EmergencyMedicalProfile::COMMON_ALLERGIES as $key => $label)
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input allergy-check"
                                                   name="allergies[]" value="{{ $key }}" id="allergy_{{ $key }}"
                                                   {{ in_array($key, $profile->allergies ?? []) ? 'checked' : '' }}>
                                            <label class="form-check-label" for="allergy_{{ $key }}">{{ $label }}</label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <input type="text" class="form-control mt-2" name="other_allergies"
                                   placeholder="حساسية أخرى (اكتب واضغط Enter)">
                        </div>

                        <div class="mt-3">
                            <label class="form-label">الأمراض المزمنة</label>
                            <textarea name="chronic_conditions_text" class="form-control" rows="2"
                                      placeholder="مثال: السكري، ضغط الدم، الربو">{{ implode(', ', $profile->chronic_conditions ?? []) }}</textarea>
                            <small class="text-muted">افصل بين الأمراض بفاصلة</small>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">الأدوية الحالية</label>
                            <textarea name="current_medications_text" class="form-control" rows="2"
                                      placeholder="مثال: ميتفورمين 500mg، أسبرين 100mg">{{ implode(', ', $profile->current_medications ?? []) }}</textarea>
                            <small class="text-muted">افصل بين الأدوية بفاصلة</small>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">ملاحظات طبية إضافية</label>
                            <textarea name="medical_notes" class="form-control" rows="2"
                                      placeholder="أي معلومات طبية أخرى يجب معرفتها">{{ $profile->medical_notes }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Medical Devices & Mobility -->
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-crutch me-2"></i>الأجهزة الطبية والحركة</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">الأجهزة الطبية</label>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" name="has_pacemaker"
                                                   id="has_pacemaker" {{ $profile->has_pacemaker ? 'checked' : '' }}>
                                            <label class="form-check-label" for="has_pacemaker">
                                                <i class="bi bi-heart-pulse me-1"></i>منظم ضربات القلب
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" name="has_hearing_aid"
                                                   id="has_hearing_aid" {{ $profile->has_hearing_aid ? 'checked' : '' }}>
                                            <label class="form-check-label" for="has_hearing_aid">
                                                <i class="bi bi-ear me-1"></i>سماعة أذن
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" name="wears_glasses"
                                                   id="wears_glasses" {{ $profile->wears_glasses ? 'checked' : '' }}>
                                            <label class="form-check-label" for="wears_glasses">
                                                <i class="bi bi-eyeglasses me-1"></i>نظارات طبية
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">وسائل المساعدة على الحركة</label>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" name="uses_wheelchair"
                                                   id="uses_wheelchair" {{ $profile->uses_wheelchair ? 'checked' : '' }}>
                                            <label class="form-check-label" for="uses_wheelchair">
                                                <i class="bi bi-person-wheelchair me-1"></i>كرسي متحرك
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" name="uses_cane_walker"
                                                   id="uses_cane_walker" {{ $profile->uses_cane_walker ? 'checked' : '' }}>
                                            <label class="form-check-label" for="uses_cane_walker">
                                                <i class="bi bi-crutch me-1"></i>عصا / مشاية
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">مستوى الحركة</label>
                                <select name="mobility_level" class="form-select">
                                    <option value="full" {{ $profile->mobility_level === 'full' ? 'selected' : '' }}>كاملة</option>
                                    <option value="limited" {{ $profile->mobility_level === 'limited' ? 'selected' : '' }}>محدودة</option>
                                    <option value="wheelchair" {{ $profile->mobility_level === 'wheelchair' ? 'selected' : '' }}>كرسي متحرك</option>
                                    <option value="bedridden" {{ $profile->mobility_level === 'bedridden' ? 'selected' : '' }}>طريح الفراش</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="needs_evacuation_assistance"
                                           id="needs_evacuation_assistance" {{ $profile->needs_evacuation_assistance ? 'checked' : '' }}>
                                    <label class="form-check-label fw-bold" for="needs_evacuation_assistance">
                                        أحتاج مساعدة خاصة أثناء الإخلاء
                                    </label>
                                </div>
                            </div>

                            <div class="col-12" id="assistanceDetails" style="{{ $profile->needs_evacuation_assistance ? '' : 'display:none' }}">
                                <label class="form-label">تفاصيل المساعدة المطلوبة</label>
                                <textarea name="assistance_requirements" class="form-control" rows="2"
                                          placeholder="مثال: أحتاج مرافق للمساعدة على النزول من السلالم">{{ $profile->assistance_requirements }}</textarea>
                            </div>

                            <div class="col-12">
                                <label class="form-label">تعليمات الإخلاء الخاصة</label>
                                <textarea name="evacuation_instructions" class="form-control" rows="2"
                                          placeholder="أي تعليمات خاصة لفريق الطوارئ">{{ $profile->evacuation_instructions }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Emergency Contacts -->
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-telephone me-2"></i>جهات الاتصال في الطوارئ</h5>
                    </div>
                    <div class="card-body">
                        <h6 class="text-muted mb-3">الاتصال الأول (رئيسي)</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">الاسم</label>
                                <input type="text" name="emergency_contact_1_name" class="form-control"
                                       value="{{ $profile->emergency_contact_1_name }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">رقم الهاتف</label>
                                <input type="tel" name="emergency_contact_1_phone" class="form-control"
                                       value="{{ $profile->emergency_contact_1_phone }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">صلة القرابة</label>
                                <select name="emergency_contact_1_relation" class="form-select">
                                    <option value="">اختر</option>
                                    @foreach(['زوج/زوجة', 'أب', 'أم', 'ابن', 'ابنة', 'أخ', 'أخت', 'صديق', 'أخرى'] as $rel)
                                        <option value="{{ $rel }}" {{ $profile->emergency_contact_1_relation === $rel ? 'selected' : '' }}>{{ $rel }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <hr class="my-4">

                        <h6 class="text-muted mb-3">الاتصال الثاني (احتياطي)</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">الاسم</label>
                                <input type="text" name="emergency_contact_2_name" class="form-control"
                                       value="{{ $profile->emergency_contact_2_name }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">رقم الهاتف</label>
                                <input type="tel" name="emergency_contact_2_phone" class="form-control"
                                       value="{{ $profile->emergency_contact_2_phone }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">صلة القرابة</label>
                                <select name="emergency_contact_2_relation" class="form-select">
                                    <option value="">اختر</option>
                                    @foreach(['زوج/زوجة', 'أب', 'أم', 'ابن', 'ابنة', 'أخ', 'أخت', 'صديق', 'أخرى'] as $rel)
                                        <option value="{{ $rel }}" {{ $profile->emergency_contact_2_relation === $rel ? 'selected' : '' }}>{{ $rel }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Medical Provider & Privacy -->
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-hospital me-2"></i>معلومات الرعاية الصحية</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">اسم الطبيب المعالج</label>
                                <input type="text" name="primary_physician_name" class="form-control"
                                       value="{{ $profile->primary_physician_name }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">رقم هاتف الطبيب</label>
                                <input type="tel" name="primary_physician_phone" class="form-control"
                                       value="{{ $profile->primary_physician_phone }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label">المستشفى المفضل</label>
                                <input type="text" name="preferred_hospital" class="form-control"
                                       value="{{ $profile->preferred_hospital }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">شركة التأمين</label>
                                <input type="text" name="insurance_provider" class="form-control"
                                       value="{{ $profile->insurance_provider }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">رقم وثيقة التأمين</label>
                                <input type="text" name="insurance_policy_number" class="form-control"
                                       value="{{ $profile->insurance_policy_number }}">
                            </div>
                        </div>

                        <hr class="my-4">

                        <h6 class="text-muted mb-3"><i class="bi bi-shield-lock me-2"></i>إعدادات الخصوصية</h6>
                        <div class="form-check mb-2">
                            <input type="checkbox" class="form-check-input" name="share_with_responders"
                                   id="share_with_responders" {{ $profile->share_with_responders ? 'checked' : '' }}>
                            <label class="form-check-label" for="share_with_responders">
                                مشاركة المعلومات مع فريق الاستجابة للطوارئ
                            </label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="share_with_medical_team"
                                   id="share_with_medical_team" {{ $profile->share_with_medical_team ? 'checked' : '' }}>
                            <label class="form-check-label" for="share_with_medical_team">
                                مشاركة المعلومات مع الفريق الطبي
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center mt-4">
            <button type="submit" class="btn btn-primary btn-lg px-5">
                <i class="bi bi-check-circle me-2"></i>حفظ التغييرات
            </button>
        </div>
    </form>
</div>

@push('scripts')
<script>
document.getElementById('needs_evacuation_assistance').addEventListener('change', function() {
    document.getElementById('assistanceDetails').style.display = this.checked ? 'block' : 'none';
});

document.getElementById('medicalProfileForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const data = {};

    // Process form data
    data.blood_type = formData.get('blood_type') || null;
    data.allergies = Array.from(document.querySelectorAll('.allergy-check:checked')).map(c => c.value);
    data.chronic_conditions = formData.get('chronic_conditions_text')?.split(',').map(s => s.trim()).filter(Boolean) || [];
    data.current_medications = formData.get('current_medications_text')?.split(',').map(s => s.trim()).filter(Boolean) || [];
    data.medical_notes = formData.get('medical_notes') || null;

    // Checkboxes
    data.has_pacemaker = document.getElementById('has_pacemaker').checked;
    data.has_hearing_aid = document.getElementById('has_hearing_aid').checked;
    data.wears_glasses = document.getElementById('wears_glasses').checked;
    data.uses_wheelchair = document.getElementById('uses_wheelchair').checked;
    data.uses_cane_walker = document.getElementById('uses_cane_walker').checked;
    data.needs_evacuation_assistance = document.getElementById('needs_evacuation_assistance').checked;

    data.mobility_level = formData.get('mobility_level');
    data.assistance_requirements = formData.get('assistance_requirements') || null;
    data.evacuation_instructions = formData.get('evacuation_instructions') || null;

    // Emergency contacts
    data.emergency_contact_1_name = formData.get('emergency_contact_1_name') || null;
    data.emergency_contact_1_phone = formData.get('emergency_contact_1_phone') || null;
    data.emergency_contact_1_relation = formData.get('emergency_contact_1_relation') || null;
    data.emergency_contact_2_name = formData.get('emergency_contact_2_name') || null;
    data.emergency_contact_2_phone = formData.get('emergency_contact_2_phone') || null;
    data.emergency_contact_2_relation = formData.get('emergency_contact_2_relation') || null;

    // Medical provider
    data.primary_physician_name = formData.get('primary_physician_name') || null;
    data.primary_physician_phone = formData.get('primary_physician_phone') || null;
    data.preferred_hospital = formData.get('preferred_hospital') || null;
    data.insurance_provider = formData.get('insurance_provider') || null;
    data.insurance_policy_number = formData.get('insurance_policy_number') || null;

    // Privacy
    data.share_with_responders = document.getElementById('share_with_responders').checked;
    data.share_with_medical_team = document.getElementById('share_with_medical_team').checked;

    try {
        const response = await fetch('/api/emergency/medical/my-profile', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            alert('تم حفظ الملف الطبي بنجاح');
            location.reload();
        } else {
            alert(result.message || 'حدث خطأ في الحفظ');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('حدث خطأ في الاتصال');
    }
});
</script>
@endpush
@endsection
