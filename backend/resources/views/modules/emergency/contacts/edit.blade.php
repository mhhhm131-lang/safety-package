@extends('layouts.app')

@section('page_title', 'تعديل جهة الاتصال')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0" style="color: var(--text-main);">
            <i class="bi bi-pencil me-2"></i>تعديل: {{ $contact->name }}
        </h4>
        <a href="{{ route('emergency.contacts.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-right me-1"></i>الرجوع
        </a>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <form action="{{ route('emergency.contacts.update', $contact) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">معلومات جهة الاتصال</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">الاسم <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $contact->name) }}" required>
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">الدور/الوظيفة</label>
                                <input type="text" name="role" class="form-control @error('role') is-invalid @enderror" value="{{ old('role', $contact->role) }}">
                                @error('role')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">رقم الهاتف <span class="text-danger">*</span></label>
                                <input type="text" name="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone', $contact->phone) }}" required>
                                @error('phone')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">البريد الإلكتروني</label>
                                <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $contact->email) }}">
                                @error('email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">المبنى</label>
                                <select name="building_id" class="form-select @error('building_id') is-invalid @enderror">
                                    <option value="">عام (جميع المباني)</option>
                                    @foreach($buildings as $building)
                                        <option value="{{ $building->id }}" {{ old('building_id', $contact->building_id) == $building->id ? 'selected' : '' }}>
                                            {{ $building->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('building_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">نوع جهة الاتصال <span class="text-danger">*</span></label>
                                <select name="contact_type" class="form-select @error('contact_type') is-invalid @enderror" required>
                                    <option value="internal" {{ old('contact_type', $contact->contact_type) === 'internal' ? 'selected' : '' }}>داخلي</option>
                                    <option value="external" {{ old('contact_type', $contact->contact_type) === 'external' ? 'selected' : '' }}>خارجي</option>
                                </select>
                                @error('contact_type')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">الأولوية <span class="text-danger">*</span></label>
                                <input type="number" name="priority" class="form-control @error('priority') is-invalid @enderror" value="{{ old('priority', $contact->priority) }}" min="1" max="100" required>
                                @error('priority')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label class="form-label">ملاحظات</label>
                                <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="2">{{ old('notes', $contact->notes) }}</textarea>
                                @error('notes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <div class="form-check">
                                    <input type="checkbox" name="auto_notify" class="form-check-input" id="auto_notify" value="1" {{ old('auto_notify', $contact->auto_notify) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="auto_notify">
                                        <i class="bi bi-bell text-warning me-1"></i>
                                        إشعار تلقائي عند حالات الطوارئ
                                    </label>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-check">
                                    <input type="checkbox" name="is_active" class="form-check-input" id="is_active" value="1" {{ old('is_active', $contact->is_active) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_active">جهة الاتصال نشطة</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <a href="{{ route('emergency.contacts.index') }}" class="btn btn-outline-secondary me-2">إلغاء</a>
                    <button type="submit" class="btn btn-accent">
                        <i class="bi bi-check-lg me-1"></i>حفظ التعديلات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
