@extends('layouts.app')

@section('title', 'إضافة كاميرا')

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="d-flex align-items-center mb-4">
                <a href="{{ route('emergency.iot.cameras.dashboard') }}" class="btn btn-outline-secondary me-3">
                    <i class="bi bi-arrow-right"></i>
                </a>
                <h1 class="h3 mb-0">
                    <i class="bi bi-camera-video-fill me-2"></i>إضافة كاميرا جديدة
                </h1>
            </div>

            <div class="card">
                <div class="card-body">
                    <form action="{{ route('emergency.iot.cameras.store') }}" method="POST">
                        @csrf

                        <div class="row g-3">
                            <!-- Basic Info -->
                            <div class="col-md-8">
                                <label class="form-label">اسم الكاميرا <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                                       value="{{ old('name') }}" required placeholder="مثال: كاميرا المدخل الرئيسي">
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">معرف الكاميرا (خارجي)</label>
                                <input type="text" name="camera_id" class="form-control @error('camera_id') is-invalid @enderror"
                                       value="{{ old('camera_id') }}" placeholder="CAM-001">
                                @error('camera_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">المبنى</label>
                                <select name="building_id" class="form-select @error('building_id') is-invalid @enderror">
                                    <option value="">-- اختر المبنى --</option>
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
                                <label class="form-label">المكان (المعهد)</label>
                                <select name="place_id" class="form-select">
                                    <option value="">-- بلا مكان محدد --</option>
                                    @foreach($places as $pl)
                                        <option value="{{ $pl->id }}" {{ old('place_id') == $pl->id ? 'selected' : '' }}>{{ $pl->code }} — {{ $pl->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">الموقع / الوصف</label>
                                <input type="text" name="location" class="form-control @error('location') is-invalid @enderror"
                                       value="{{ old('location') }}" placeholder="مثال: الدور الأرضي - بوابة 1">
                                @error('location')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">نوع الكاميرا <span class="text-danger">*</span></label>
                                <select name="type" class="form-select @error('type') is-invalid @enderror" required>
                                    <option value="fixed" {{ old('type') == 'fixed' ? 'selected' : '' }}>ثابتة</option>
                                    <option value="ptz" {{ old('type') == 'ptz' ? 'selected' : '' }}>PTZ متحركة</option>
                                    <option value="dome" {{ old('type') == 'dome' ? 'selected' : '' }}>قبة</option>
                                    <option value="thermal" {{ old('type') == 'thermal' ? 'selected' : '' }}>حرارية</option>
                                </select>
                                @error('type')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">رقم الطابق</label>
                                <input type="number" name="floor_number" class="form-control @error('floor_number') is-invalid @enderror"
                                       value="{{ old('floor_number') }}" placeholder="0">
                                @error('floor_number')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- URLs -->
                            <div class="col-12">
                                <hr class="my-2">
                                <h6 class="text-muted"><i class="bi bi-link-45deg me-1"></i>روابط البث</h6>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">رابط البث المباشر</label>
                                <input type="url" name="stream_url" class="form-control @error('stream_url') is-invalid @enderror"
                                       value="{{ old('stream_url') }}" placeholder="rtsp:// أو http://">
                                @error('stream_url')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <small class="text-muted">RTSP, HLS, أو رابط ويب</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">رابط صورة المعاينة</label>
                                <input type="url" name="snapshot_url" class="form-control @error('snapshot_url') is-invalid @enderror"
                                       value="{{ old('snapshot_url') }}" placeholder="http://...">
                                @error('snapshot_url')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <small class="text-muted">صورة JPEG للمعاينة</small>
                            </div>

                            <!-- Options -->
                            <div class="col-12">
                                <hr class="my-2">
                                <h6 class="text-muted"><i class="bi bi-gear me-1"></i>الخيارات</h6>
                            </div>

                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_emergency_priority"
                                           id="is_emergency_priority" value="1" {{ old('is_emergency_priority') ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_emergency_priority">
                                        <i class="bi bi-star text-warning me-1"></i>أولوية في الطوارئ
                                    </label>
                                </div>
                                <small class="text-muted">تظهر أولاً أثناء حالات الطوارئ</small>
                            </div>

                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="has_audio"
                                           id="has_audio" value="1" {{ old('has_audio') ? 'checked' : '' }}>
                                    <label class="form-check-label" for="has_audio">
                                        <i class="bi bi-mic me-1"></i>تدعم الصوت
                                    </label>
                                </div>
                                <small class="text-muted">الكاميرا لديها ميكروفون</small>
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="d-flex justify-content-end gap-2">
                            <a href="{{ route('emergency.iot.cameras.dashboard') }}" class="btn btn-outline-secondary">
                                إلغاء
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>حفظ الكاميرا
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
