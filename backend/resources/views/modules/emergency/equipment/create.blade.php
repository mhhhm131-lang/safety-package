@extends('layouts.app')

@section('page_title', 'إضافة معدة طوارئ')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0" style="color: var(--text-main);">
            <i class="bi bi-tools me-2"></i>إضافة معدة طوارئ
        </h4>
        <a href="{{ route('emergency.equipment.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-right me-1"></i>الرجوع
        </a>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <form action="{{ route('emergency.equipment.store') }}" method="POST">
                @csrf

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">معلومات المعدة</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">نوع المعدة <span class="text-danger">*</span></label>
                                <select name="equipment_type" class="form-select @error('equipment_type') is-invalid @enderror" required>
                                    <option value="">اختر النوع...</option>
                                    <option value="fire_extinguisher" {{ old('equipment_type') === 'fire_extinguisher' ? 'selected' : '' }}>طفاية حريق</option>
                                    <option value="fire_hose" {{ old('equipment_type') === 'fire_hose' ? 'selected' : '' }}>خرطوم حريق</option>
                                    <option value="smoke_detector" {{ old('equipment_type') === 'smoke_detector' ? 'selected' : '' }}>كاشف دخان</option>
                                    <option value="heat_detector" {{ old('equipment_type') === 'heat_detector' ? 'selected' : '' }}>كاشف حرارة</option>
                                    <option value="alarm_bell" {{ old('equipment_type') === 'alarm_bell' ? 'selected' : '' }}>جرس إنذار</option>
                                    <option value="exit_sign" {{ old('equipment_type') === 'exit_sign' ? 'selected' : '' }}>لافتة خروج</option>
                                    <option value="emergency_light" {{ old('equipment_type') === 'emergency_light' ? 'selected' : '' }}>إضاءة طوارئ</option>
                                    <option value="first_aid_kit" {{ old('equipment_type') === 'first_aid_kit' ? 'selected' : '' }}>صندوق إسعاف</option>
                                    <option value="aed" {{ old('equipment_type') === 'aed' ? 'selected' : '' }}>جهاز صدمات AED</option>
                                    <option value="fire_blanket" {{ old('equipment_type') === 'fire_blanket' ? 'selected' : '' }}>بطانية حريق</option>
                                    <option value="spill_kit" {{ old('equipment_type') === 'spill_kit' ? 'selected' : '' }}>طقم تسرب</option>
                                    <option value="eyewash" {{ old('equipment_type') === 'eyewash' ? 'selected' : '' }}>غسول عيون</option>
                                    <option value="other" {{ old('equipment_type') === 'other' ? 'selected' : '' }}>أخرى</option>
                                </select>
                                @error('equipment_type')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الرمز</label>
                                <input type="text" name="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code') }}" placeholder="مثال: FE-001">
                                @error('code')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">المبنى <span class="text-danger">*</span></label>
                                <select name="building_id" class="form-select @error('building_id') is-invalid @enderror" required id="buildingSelect">
                                    <option value="">اختر المبنى...</option>
                                    @foreach($buildings as $building)
                                        <option value="{{ $building->id }}" data-floors='@json($building->floors)' {{ old('building_id') == $building->id ? 'selected' : '' }}>
                                            {{ $building->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('building_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الدور</label>
                                <select name="floor_id" class="form-select @error('floor_id') is-invalid @enderror" id="floorSelect">
                                    <option value="">اختر الدور...</option>
                                </select>
                                @error('floor_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">المكان (HZ)</label>
                                <select name="place_id" class="form-select">
                                    <option value="">—</option>
                                    @foreach($places as $p)<option value="{{ $p->id }}" {{ old('place_id') == $p->id ? 'selected' : '' }}>{{ $p->code }} — {{ $p->name }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">آخر فحص</label>
                                <input type="date" name="last_inspection_date" class="form-control" value="{{ old('last_inspection_date') }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label">وصف الموقع</label>
                                <input type="text" name="location_description" class="form-control @error('location_description') is-invalid @enderror" value="{{ old('location_description') }}" placeholder="مثال: بجوار المصعد الرئيسي">
                                @error('location_description')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">الصيانة والفحص</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">تاريخ التركيب</label>
                                <input type="date" name="install_date" class="form-control @error('install_date') is-invalid @enderror" value="{{ old('install_date') }}">
                                @error('install_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">تاريخ الانتهاء</label>
                                <input type="date" name="expiry_date" class="form-control @error('expiry_date') is-invalid @enderror" value="{{ old('expiry_date') }}">
                                @error('expiry_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">دورية الفحص <span class="text-danger">*</span></label>
                                <select name="inspection_frequency" class="form-select @error('inspection_frequency') is-invalid @enderror" required>
                                    <option value="monthly" {{ old('inspection_frequency') === 'monthly' ? 'selected' : '' }}>شهري</option>
                                    <option value="quarterly" {{ old('inspection_frequency', 'quarterly') === 'quarterly' ? 'selected' : '' }}>ربع سنوي</option>
                                    <option value="semi_annual" {{ old('inspection_frequency') === 'semi_annual' ? 'selected' : '' }}>نصف سنوي</option>
                                    <option value="annual" {{ old('inspection_frequency') === 'annual' ? 'selected' : '' }}>سنوي</option>
                                </select>
                                @error('inspection_frequency')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <a href="{{ route('emergency.equipment.index') }}" class="btn btn-outline-secondary me-2">إلغاء</a>
                    <button type="submit" class="btn btn-accent">
                        <i class="bi bi-check-lg me-1"></i>إضافة المعدة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('buildingSelect').addEventListener('change', function() {
    const floorSelect = document.getElementById('floorSelect');
    floorSelect.innerHTML = '<option value="">اختر الدور...</option>';

    const selectedOption = this.options[this.selectedIndex];
    if (selectedOption.value) {
        const floors = JSON.parse(selectedOption.dataset.floors || '[]');
        floors.sort((a, b) => a.floor_number - b.floor_number);
        floors.forEach(floor => {
            const displayName = floor.floor_number === 0 ? 'الأرضي' :
                              floor.floor_number < 0 ? `بدروم ${Math.abs(floor.floor_number)}` :
                              `الدور ${floor.floor_number}`;
            const option = document.createElement('option');
            option.value = floor.id;
            option.textContent = floor.name || displayName;
            floorSelect.appendChild(option);
        });
    }
});
</script>
@endsection
