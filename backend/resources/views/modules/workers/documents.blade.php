@extends('layouts.app')

@section('page_title', 'وثائق العامل')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="fw-bold mb-0" style="color: var(--text-main);">
            <i class="bi bi-file-earmark-text me-2"></i>وثائق العامل: {{ $worker->full_name }}
        </h5>
        <a href="{{ route('workers.show', $worker) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-right me-1"></i> العودة لملف العامل
        </a>
    </div>

    {{-- نموذج رفع وثيقة --}}
    <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <div class="card-header">
            <i class="bi bi-cloud-upload me-1"></i> رفع وثيقة جديدة
        </div>
        <div class="card-body">
            <form action="{{ route('workers.documents.store', $worker) }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="title" class="form-label">اسم الوثيقة <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="title" class="form-control @error('title') is-invalid @enderror"
                               value="{{ old('title') }}" required>
                        @error('title')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="document_type" class="form-label">نوع الوثيقة <span class="text-danger">*</span></label>
                        <select name="document_type" id="document_type" class="form-select @error('document_type') is-invalid @enderror" required>
                            <option value="">-- اختر النوع --</option>
                            <option value="national_id" @selected(old('document_type') == 'national_id')>هوية وطنية</option>
                            <option value="medical" @selected(old('document_type') == 'medical')>فحص طبي</option>
                            <option value="trade_certificate" @selected(old('document_type') == 'trade_certificate')>شهادة مهنية</option>
                            <option value="safety_certificate" @selected(old('document_type') == 'safety_certificate')>شهادة سلامة</option>
                            <option value="driving_license" @selected(old('document_type') == 'driving_license')>رخصة قيادة</option>
                            <option value="insurance" @selected(old('document_type') == 'insurance')>تامين</option>
                            <option value="photo" @selected(old('document_type') == 'photo')>صورة شخصية</option>
                            <option value="other" @selected(old('document_type') == 'other')>اخرى</option>
                        </select>
                        @error('document_type')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="file" class="form-label">الملف <span class="text-danger">*</span></label>
                        <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" id="file" class="form-control @error('file') is-invalid @enderror" required>
                        @error('file')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-2">
                        <label for="expiry_date" class="form-label">تاريخ الانتهاء</label>
                        <input type="date" name="expiry_date" id="expiry_date" class="form-control @error('expiry_date') is-invalid @enderror"
                               value="{{ old('expiry_date') }}">
                        @error('expiry_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="mt-3 text-end">
                    <button type="submit" class="btn btn-accent">
                        <i class="bi bi-upload me-1"></i> رفع الوثيقة
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- قائمة الوثائق --}}
    <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-folder2-open me-1"></i> الوثائق المرفقة</span>
            <span class="badge bg-secondary">{{ $documents->total() }} وثيقة</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="color: var(--text-muted);">اسم الوثيقة</th>
                            <th style="color: var(--text-muted);">النوع</th>
                            <th style="color: var(--text-muted);">تاريخ الانتهاء</th>
                            <th style="color: var(--text-muted);">الحالة</th>
                            <th style="color: var(--text-muted);">تاريخ الرفع</th>
                            <th style="color: var(--text-muted);">اجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $docTypeLabels = [
                                'national_id' => 'هوية وطنية',
                                'medical' => 'فحص طبي',
                                'trade_certificate' => 'شهادة مهنية',
                                'safety_certificate' => 'شهادة سلامة',
                                'driving_license' => 'رخصة قيادة',
                                'insurance' => 'تامين',
                                'photo' => 'صورة شخصية',
                                'other' => 'اخرى',
                            ];
                            $docTypeColors = [
                                'national_id' => 'primary',
                                'medical' => 'success',
                                'trade_certificate' => 'info',
                                'safety_certificate' => 'warning',
                                'driving_license' => 'secondary',
                                'insurance' => 'dark',
                                'photo' => 'light text-dark',
                                'other' => 'secondary',
                            ];
                        @endphp
                        @forelse($documents as $doc)
                            <tr>
                                <td style="color: var(--text-main);">{{ $doc->title ?? $doc->name }}</td>
                                <td>
                                    <span class="badge bg-{{ $docTypeColors[$doc->document_type] ?? 'secondary' }}">
                                        {{ $docTypeLabels[$doc->document_type] ?? $doc->document_type }}
                                    </span>
                                </td>
                                <td style="color: var(--text-main);" dir="ltr">
                                    {{ $doc->expiry_date ? $doc->expiry_date->format('Y-m-d') : '--' }}
                                </td>
                                <td>
                                    @if($doc->is_expired)
                                        <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>منتهية</span>
                                    @elseif($doc->is_expiring_soon)
                                        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>قاربت على الانتهاء</span>
                                    @elseif($doc->expiry_date)
                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>سارية</span>
                                    @else
                                        <span class="badge bg-secondary">بدون تاريخ</span>
                                    @endif
                                </td>
                                <td style="color: var(--text-muted);" dir="ltr">
                                    {{ $doc->created_at?->format('Y-m-d H:i') }}
                                </td>
                                <td>
                                    @if($doc->hasFile())
                                        <a href="{{ route('workers.documents.download', [$worker, $doc]) }}" target="_blank" class="btn btn-sm btn-outline-primary" title="{{ $doc->file }}">
                                            <i class="bi bi-download"></i>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-4" style="color: var(--text-muted);">
                                    <i class="bi bi-folder-x fs-3 d-block mb-2"></i>
                                    لا توجد وثائق مرفقة لهذا العامل
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if($documents->hasPages())
        <div class="d-flex justify-content-center mt-4">
            {{ $documents->withQueryString()->links() }}
        </div>
    @endif
</div>
@endsection
