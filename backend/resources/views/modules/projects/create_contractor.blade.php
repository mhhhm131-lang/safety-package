@extends('layouts.app')
@section('page_title', 'إنشاء مقاول جديد — ' . $project->name)
@section('content')
<div class="container-fluid">

    <div class="d-flex align-items-center gap-2 mb-3 small" style="color: var(--text-muted);">
        <a href="{{ route('projects.index') }}" class="text-decoration-none" style="color: var(--text-muted);">المشاريع</a>
        <i class="bi bi-chevron-left"></i>
        <a href="{{ route('projects.show', $project) }}" class="text-decoration-none" style="color: var(--text-muted);">{{ $project->name }}</a>
        <i class="bi bi-chevron-left"></i>
        <a href="{{ route('projects.contractors', $project) }}" class="text-decoration-none" style="color: var(--text-muted);">المقاولون</a>
        <i class="bi bi-chevron-left"></i>
        <span>إنشاء مقاول جديد</span>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="fw-bold mb-0" style="color: var(--text-main);">
            <i class="bi bi-person-plus me-2"></i>إنشاء مقاول جديد وربطه بـ "{{ $project->name }}"
        </h5>
        <a href="{{ route('projects.contractors', $project) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-right me-1"></i> رجوع
        </a>
    </div>

    <form method="POST" action="{{ route('projects.contractors.store-new', $project) }}">
        @csrf

        <div class="row g-4">

            {{-- Right column: contractor data --}}
            <div class="col-lg-8">

                {{-- Basic info --}}
                <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                    <div class="card-header" style="background: transparent; border-bottom: 1px solid var(--border-color);">
                        <h6 class="mb-0 fw-bold" style="color: var(--accent);"><i class="bi bi-building me-1"></i> بيانات المقاول / الشركة</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">اسم المقاول / الشركة <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control form-control-lg @error('name') is-invalid @enderror"
                                       value="{{ old('name') }}" required placeholder="مثال: شركة الإنشاءات الحديثة">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الاسم بالإنجليزية</label>
                                <input type="text" name="name_en" class="form-control" value="{{ old('name_en') }}" dir="ltr" placeholder="Modern Construction Co.">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">نوع الطرف <span class="text-danger">*</span></label>
                                <select name="party_type" class="form-select @error('party_type') is-invalid @enderror" required>
                                    <option value="contractor" @selected(old('party_type','contractor')=='contractor')>مقاول</option>
                                    <option value="service_provider" @selected(old('party_type')=='service_provider')>مزود خدمات</option>
                                    <option value="supplier" @selected(old('party_type')=='supplier')>مورد</option>
                                    <option value="consultant" @selected(old('party_type')=='consultant')>مستشار</option>
                                </select>
                                @error('party_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">السجل التجاري</label>
                                <input type="text" name="cr_number" class="form-control" value="{{ old('cr_number') }}" dir="ltr" placeholder="1234567890">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">جهة الاتصال</label>
                                <input type="text" name="contact_person" class="form-control" value="{{ old('contact_person') }}" placeholder="اسم المسؤول">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الهاتف</label>
                                <input type="text" name="phone" class="form-control" value="{{ old('phone') }}" dir="ltr" placeholder="+966 5x xxx xxxx">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">البريد الإلكتروني</label>
                                <input type="email" name="email" class="form-control" value="{{ old('email') }}" dir="ltr" placeholder="info@company.com">
                            </div>
                            <div class="col-12">
                                <label class="form-label">العنوان</label>
                                <textarea name="address" class="form-control" rows="2" placeholder="العنوان التفصيلي...">{{ old('address') }}</textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الموقع الإلكتروني</label>
                                <input type="url" name="website_url" class="form-control" value="{{ old('website_url') }}" dir="ltr" placeholder="https://...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">رابط التسجيل / بوابة المقاولين</label>
                                <input type="url" name="registration_url" class="form-control" value="{{ old('registration_url') }}" dir="ltr" placeholder="https://...">
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            {{-- Left column: contract data --}}
            <div class="col-lg-4">

                <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                    <div class="card-header" style="background: transparent; border-bottom: 1px solid var(--border-color);">
                        <h6 class="mb-0 fw-bold" style="color: var(--accent);"><i class="bi bi-file-earmark-text me-1"></i> بيانات التعاقد</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold">الدور في المشروع <span class="text-danger">*</span></label>
                            <select name="role" class="form-select @error('role') is-invalid @enderror" required>
                                @foreach($roles as $value => $label)
                                    <option value="{{ $value }}" @selected(old('role','main') == $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('role') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">نطاق الأعمال في المشروع</label>
                            <textarea name="activity_scope" class="form-control" rows="3"
                                      placeholder="مثال: تركيب الأنظمة الكهربائية في الطوابق 1-10">{{ old('activity_scope') }}</textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">تاريخ بداية العقد</label>
                            <input type="date" name="contract_start_date" class="form-control" value="{{ old('contract_start_date') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">تاريخ انتهاء العقد</label>
                            <input type="date" name="contract_end_date" class="form-control" value="{{ old('contract_end_date') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">ملاحظات العقد</label>
                            <textarea name="contract_notes" class="form-control" rows="2">{{ old('contract_notes') }}</textarea>
                        </div>
                    </div>
                </div>

                {{-- Submit --}}
                <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                    <div class="card-body p-4">
                        <div class="alert alert-info mb-3 py-2 small">
                            <i class="bi bi-lightbulb me-1"></i>
                            إذا حددت النشاط الاقتصادي سيتم تهيئة سجل المخاطر تلقائياً ثم بناء الهيكل التنظيمي.
                        </div>
                        <button type="submit" class="btn btn-accent w-100 mb-2">
                            <i class="bi bi-person-check me-1"></i> إنشاء وربط بالمشروع
                        </button>
                        <a href="{{ route('projects.contractors', $project) }}" class="btn btn-outline-secondary w-100">إلغاء</a>
                    </div>
                </div>

            </div>
        </div>
    </form>
</div>
@endsection
