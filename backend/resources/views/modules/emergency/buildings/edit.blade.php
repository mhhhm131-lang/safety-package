@extends('layouts.app')

@section('page_title', 'تعديل ' . $building->name)

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0" style="color: var(--text-main);">
            <i class="bi bi-pencil me-2"></i>تعديل: {{ $building->name }}
        </h4>
        <a href="{{ route('emergency.buildings.show', $building) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-right me-1"></i>الرجوع
        </a>
    </div>

    <form action="{{ route('emergency.buildings.update', $building) }}" method="POST">
        @csrf
        @method('PUT')

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
                                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $building->name) }}" required>
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الاسم بالإنجليزية</label>
                                <input type="text" name="name_en" class="form-control @error('name_en') is-invalid @enderror" value="{{ old('name_en', $building->name_en) }}">
                                @error('name_en')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">رمز المبنى</label>
                                <input type="text" name="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code', $building->code) }}">
                                @error('code')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">نوع المبنى <span class="text-danger">*</span></label>
                                <select name="building_type" class="form-select @error('building_type') is-invalid @enderror" required>
                                    <option value="office" {{ old('building_type', $building->building_type) === 'office' ? 'selected' : '' }}>مكتبي</option>
                                    <option value="industrial" {{ old('building_type', $building->building_type) === 'industrial' ? 'selected' : '' }}>صناعي</option>
                                    <option value="educational" {{ old('building_type', $building->building_type) === 'educational' ? 'selected' : '' }}>تعليمي</option>
                                    <option value="medical" {{ old('building_type', $building->building_type) === 'medical' ? 'selected' : '' }}>طبي</option>
                                    <option value="residential" {{ old('building_type', $building->building_type) === 'residential' ? 'selected' : '' }}>سكني</option>
                                    <option value="commercial" {{ old('building_type', $building->building_type) === 'commercial' ? 'selected' : '' }}>تجاري</option>
                                    <option value="government" {{ old('building_type', $building->building_type) === 'government' ? 'selected' : '' }}>حكومي</option>
                                    <option value="other" {{ old('building_type', $building->building_type) === 'other' ? 'selected' : '' }}>أخرى</option>
                                </select>
                                @error('building_type')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">العنوان</label>
                                <textarea name="address" class="form-control @error('address') is-invalid @enderror" rows="2">{{ old('address', $building->address) }}</textarea>
                                @error('address')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">السعة الإجمالية</label>
                                <input type="number" name="total_capacity" class="form-control @error('total_capacity') is-invalid @enderror" value="{{ old('total_capacity', $building->total_capacity) }}" min="1">
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
                        <h5 class="mb-0">الحالة والمخاطر</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">حالة المبنى <span class="text-danger">*</span></label>
                            <select name="status" class="form-select @error('status') is-invalid @enderror" required>
                                <option value="active" {{ old('status', $building->status) === 'active' ? 'selected' : '' }}>نشط</option>
                                <option value="inactive" {{ old('status', $building->status) === 'inactive' ? 'selected' : '' }}>غير نشط</option>
                                <option value="under_maintenance" {{ old('status', $building->status) === 'under_maintenance' ? 'selected' : '' }}>تحت الصيانة</option>
                            </select>
                            @error('status')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">مستوى المخاطر <span class="text-danger">*</span></label>
                            <select name="risk_level" class="form-select @error('risk_level') is-invalid @enderror" required>
                                <option value="low" {{ old('risk_level', $building->risk_level) === 'low' ? 'selected' : '' }}>منخفض</option>
                                <option value="medium" {{ old('risk_level', $building->risk_level) === 'medium' ? 'selected' : '' }}>متوسط</option>
                                <option value="high" {{ old('risk_level', $building->risk_level) === 'high' ? 'selected' : '' }}>عالي</option>
                                <option value="critical" {{ old('risk_level', $building->risk_level) === 'critical' ? 'selected' : '' }}>حرج</option>
                            </select>
                            @error('risk_level')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
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
                                <input type="number" name="latitude" class="form-control @error('latitude') is-invalid @enderror" value="{{ old('latitude', $building->latitude) }}" step="any">
                                @error('latitude')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6">
                                <label class="form-label">خط الطول</label>
                                <input type="number" name="longitude" class="form-control @error('longitude') is-invalid @enderror" value="{{ old('longitude', $building->longitude) }}" step="any">
                                @error('longitude')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-accent btn-lg">
                        <i class="bi bi-check-lg me-1"></i>حفظ التغييرات
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection
