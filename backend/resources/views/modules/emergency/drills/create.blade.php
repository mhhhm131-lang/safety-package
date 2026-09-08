@extends('layouts.app')

@section('page_title', 'جدولة تمرين إخلاء')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0" style="color: var(--text-main);">
            <i class="bi bi-calendar-plus me-2"></i>جدولة تمرين إخلاء
        </h4>
        <a href="{{ route('emergency.drills.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-right me-1"></i>الرجوع
        </a>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <form action="{{ route('emergency.drills.store') }}" method="POST">
                @csrf

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">معلومات التمرين</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">المبنى <span class="text-danger">*</span></label>
                                <select name="building_id" class="form-select @error('building_id') is-invalid @enderror" required>
                                    <option value="">اختر المبنى...</option>
                                    @foreach($buildings as $building)
                                        <option value="{{ $building->id }}" {{ old('building_id') == $building->id ? 'selected' : '' }}>
                                            {{ $building->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('building_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">المكان (خطة الاستجابة التي تُمرَّن)</label>
                                <select name="place_id" class="form-select @error('place_id') is-invalid @enderror">
                                    <option value="">— المبنى كله —</option>
                                    @foreach($places as $p)
                                        <option value="{{ $p->id }}" {{ old('place_id') == $p->id ? 'selected' : '' }}>{{ $p->code }} — {{ $p->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">نوع التمرين <span class="text-danger">*</span></label>
                                <select name="drill_type" class="form-select @error('drill_type') is-invalid @enderror" required>
                                    <option value="">اختر النوع...</option>
                                    <option value="fire" {{ old('drill_type') === 'fire' ? 'selected' : '' }}>إخلاء حريق</option>
                                    <option value="evacuation" {{ old('drill_type') === 'evacuation' ? 'selected' : '' }}>إخلاء عام</option>
                                    <option value="earthquake" {{ old('drill_type') === 'earthquake' ? 'selected' : '' }}>إخلاء زلزال</option>
                                    <option value="chemical" {{ old('drill_type') === 'chemical' ? 'selected' : '' }}>تسرب كيميائي</option>
                                    <option value="full_scale" {{ old('drill_type') === 'full_scale' ? 'selected' : '' }}>تمرين شامل</option>
                                    <option value="tabletop" {{ old('drill_type') === 'tabletop' ? 'selected' : '' }}>تمرين طاولة</option>
                                    <option value="announced" {{ old('drill_type') === 'announced' ? 'selected' : '' }}>تمرين معلن</option>
                                    <option value="unannounced" {{ old('drill_type') === 'unannounced' ? 'selected' : '' }}>تمرين مفاجئ</option>
                                </select>
                                @error('drill_type')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">تاريخ ووقت التمرين <span class="text-danger">*</span></label>
                                <input type="datetime-local" name="scheduled_at" class="form-control @error('scheduled_at') is-invalid @enderror" value="{{ old('scheduled_at') }}" required>
                                @error('scheduled_at')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">عدد المشاركين المتوقع</label>
                                <input type="number" name="expected_participants" class="form-control @error('expected_participants') is-invalid @enderror" value="{{ old('expected_participants') }}" min="1">
                                @error('expected_participants')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">الوقت المستهدف (ثانية)</label>
                                <input type="number" name="target_time_sec" class="form-control @error('target_time_sec') is-invalid @enderror" value="{{ old('target_time_sec') }}" min="1" placeholder="مثال: 300">
                                @error('target_time_sec')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">السيناريو</label>
                                <textarea name="scenario" class="form-control @error('scenario') is-invalid @enderror" rows="3" placeholder="وصف السيناريو المفترض للتمرين...">{{ old('scenario') }}</textarea>
                                @error('scenario')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">الأهداف</label>
                                <textarea name="objectives" class="form-control @error('objectives') is-invalid @enderror" rows="3" placeholder="أهداف التمرين المراد تحقيقها...">{{ old('objectives') }}</textarea>
                                @error('objectives')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <a href="{{ route('emergency.drills.index') }}" class="btn btn-outline-secondary me-2">إلغاء</a>
                    <button type="submit" class="btn btn-accent">
                        <i class="bi bi-calendar-check me-1"></i>جدولة التمرين
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
