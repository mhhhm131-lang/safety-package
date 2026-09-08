@extends('layouts.app')

@section('page_title', 'إضافة مبنى')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0" style="color: var(--text-main);">
            <i class="bi bi-building-add me-2"></i>إضافة مبنى جديد
        </h4>
        <a href="{{ route('emergency.buildings.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-right me-1"></i>الرجوع
        </a>
    </div>

    <form action="{{ route('emergency.buildings.store') }}" method="POST">
        @csrf

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">معلومات المبنى</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">اسم المبنى <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required>
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الاسم بالإنجليزية</label>
                                <input type="text" name="name_en" class="form-control @error('name_en') is-invalid @enderror" value="{{ old('name_en') }}">
                                @error('name_en')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">رمز المبنى</label>
                                <input type="text" name="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code') }}" placeholder="مثال: B001">
                                @error('code')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">نوع المبنى <span class="text-danger">*</span></label>
                                <select name="building_type" class="form-select @error('building_type') is-invalid @enderror" required>
                                    <option value="">اختر...</option>
                                    <option value="office" {{ old('building_type') === 'office' ? 'selected' : '' }}>مكتبي</option>
                                    <option value="industrial" {{ old('building_type') === 'industrial' ? 'selected' : '' }}>صناعي</option>
                                    <option value="educational" {{ old('building_type') === 'educational' ? 'selected' : '' }}>تعليمي</option>
                                    <option value="medical" {{ old('building_type') === 'medical' ? 'selected' : '' }}>طبي</option>
                                    <option value="residential" {{ old('building_type') === 'residential' ? 'selected' : '' }}>سكني</option>
                                    <option value="commercial" {{ old('building_type') === 'commercial' ? 'selected' : '' }}>تجاري</option>
                                    <option value="government" {{ old('building_type') === 'government' ? 'selected' : '' }}>حكومي</option>
                                    <option value="other" {{ old('building_type') === 'other' ? 'selected' : '' }}>أخرى</option>
                                </select>
                                @error('building_type')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">العنوان</label>
                                <textarea name="address" class="form-control @error('address') is-invalid @enderror" rows="2">{{ old('address') }}</textarea>
                                @error('address')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">الأدوار والسعة</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">عدد الأدوار <span class="text-danger">*</span></label>
                                <input type="number" name="floors_count" class="form-control @error('floors_count') is-invalid @enderror" value="{{ old('floors_count', 1) }}" min="1" max="200" required>
                                <small class="text-muted">عدد الأدوار فوق الأرض</small>
                                @error('floors_count')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">الأدوار السفلية (بدروم)</label>
                                <input type="number" name="basement_floors" class="form-control @error('basement_floors') is-invalid @enderror" value="{{ old('basement_floors', 0) }}" min="0" max="20">
                                @error('basement_floors')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">السعة الإجمالية</label>
                                <input type="number" name="total_capacity" class="form-control @error('total_capacity') is-invalid @enderror" value="{{ old('total_capacity') }}" min="1">
                                <small class="text-muted">عدد الأشخاص</small>
                                @error('total_capacity')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">مستوى المخاطر</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">مستوى المخاطر <span class="text-danger">*</span></label>
                            <select name="risk_level" class="form-select @error('risk_level') is-invalid @enderror" required>
                                <option value="low" {{ old('risk_level') === 'low' ? 'selected' : '' }}>منخفض</option>
                                <option value="medium" {{ old('risk_level', 'medium') === 'medium' ? 'selected' : '' }}>متوسط</option>
                                <option value="high" {{ old('risk_level') === 'high' ? 'selected' : '' }}>عالي</option>
                                <option value="critical" {{ old('risk_level') === 'critical' ? 'selected' : '' }}>حرج</option>
                            </select>
                            @error('risk_level')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="alert alert-info small mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            مستوى المخاطر يحدد إجراءات الطوارئ المطلوبة
                        </div>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">الموقع الجغرافي</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label">خط العرض</label>
                                <input type="number" name="latitude" class="form-control @error('latitude') is-invalid @enderror" value="{{ old('latitude') }}" step="any" min="-90" max="90">
                                @error('latitude')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6">
                                <label class="form-label">خط الطول</label>
                                <input type="number" name="longitude" class="form-control @error('longitude') is-invalid @enderror" value="{{ old('longitude') }}" step="any" min="-180" max="180">
                                @error('longitude')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-accent btn-lg">
                        <i class="bi bi-check-lg me-1"></i>إنشاء المبنى
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection
