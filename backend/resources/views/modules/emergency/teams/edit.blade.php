@extends('layouts.app')

@section('page_title', 'تعديل فريق: ' . $team->name)

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0" style="color: var(--text-main);">
            <i class="bi bi-pencil me-2"></i>تعديل فريق: {{ $team->name }}
        </h4>
        <a href="{{ route('emergency.teams.show', $team) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-right me-1"></i>الرجوع
        </a>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <form action="{{ route('emergency.teams.update', $team) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">معلومات الفريق</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">المكان</label>
                                <select name="place_id" class="form-select">
                                    <option value="">— عام —</option>
                                    @foreach($places as $p)<option value="{{ $p->id }}" {{ old('place_id', $team->place_id) == $p->id ? 'selected' : '' }}>{{ $p->code }} — {{ $p->name }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">المبنى <span class="text-danger">*</span></label>
                                <select name="building_id" class="form-select @error('building_id') is-invalid @enderror" required>
                                    <option value="">اختر المبنى...</option>
                                    @foreach($buildings as $building)
                                        <option value="{{ $building->id }}" {{ old('building_id', $team->building_id) == $building->id ? 'selected' : '' }}>
                                            {{ $building->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('building_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">نوع الفريق <span class="text-danger">*</span></label>
                                <select name="team_type" class="form-select @error('team_type') is-invalid @enderror" required>
                                    <option value="">اختر النوع...</option>
                                    <option value="command" {{ old('team_type', $team->team_type) === 'command' ? 'selected' : '' }}>فريق القيادة</option>
                                    <option value="fire_warden" {{ old('team_type', $team->team_type) === 'fire_warden' ? 'selected' : '' }}>مراقبو الحريق</option>
                                    <option value="first_aid" {{ old('team_type', $team->team_type) === 'first_aid' ? 'selected' : '' }}>الإسعافات الأولية</option>
                                    <option value="evacuation" {{ old('team_type', $team->team_type) === 'evacuation' ? 'selected' : '' }}>فريق الإخلاء</option>
                                    <option value="search_rescue" {{ old('team_type', $team->team_type) === 'search_rescue' ? 'selected' : '' }}>البحث والإنقاذ</option>
                                    <option value="communication" {{ old('team_type', $team->team_type) === 'communication' ? 'selected' : '' }}>الاتصالات</option>
                                    <option value="security" {{ old('team_type', $team->team_type) === 'security' ? 'selected' : '' }}>الأمن</option>
                                </select>
                                @error('team_type')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">اسم الفريق <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $team->name) }}" required>
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">الفترة <span class="text-danger">*</span></label>
                                <select name="shift" class="form-select @error('shift') is-invalid @enderror" required>
                                    <option value="all" {{ old('shift', $team->shift) === 'all' ? 'selected' : '' }}>جميع الفترات</option>
                                    <option value="morning" {{ old('shift', $team->shift) === 'morning' ? 'selected' : '' }}>صباحي</option>
                                    <option value="evening" {{ old('shift', $team->shift) === 'evening' ? 'selected' : '' }}>مسائي</option>
                                    <option value="night" {{ old('shift', $team->shift) === 'night' ? 'selected' : '' }}>ليلي</option>
                                </select>
                                @error('shift')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label class="form-label">الوصف</label>
                                <textarea name="description" class="form-control @error('description') is-invalid @enderror" rows="3">{{ old('description', $team->description) }}</textarea>
                                @error('description')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <div class="form-check">
                                    <input type="checkbox" name="is_active" class="form-check-input" id="is_active" value="1" {{ old('is_active', $team->is_active) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_active">الفريق نشط</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <a href="{{ route('emergency.teams.show', $team) }}" class="btn btn-outline-secondary me-2">إلغاء</a>
                    <button type="submit" class="btn btn-accent">
                        <i class="bi bi-check-lg me-1"></i>حفظ التعديلات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
