@extends('layouts.app')

@section('page_title', 'إضافة مقاول للمشروع')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h5 class="fw-bold mb-1" style="color: var(--text-main);">
                <i class="bi bi-person-plus me-2"></i>إضافة مقاول
            </h5>
            <small style="color: var(--text-muted);">{{ $project->name }}</small>
        </div>
        <a href="{{ route('projects.contractors', $project) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-right me-1"></i> العودة
        </a>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">

            {{-- Tip --}}

            <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header" style="background: transparent; border-bottom: 1px solid var(--border-color);">
                    <h6 class="mb-0 fw-bold" style="color: var(--text-main);">بيانات التعيين</h6>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="{{ route('projects.contractors.store', $project) }}">
                        @csrf

                        <div class="row g-3">

                            {{-- Contractor --}}
                            <div class="col-md-12">
                                <label class="form-label fw-bold">المقاول <span class="text-danger">*</span></label>
                                <select class="form-select @error('external_party_id') is-invalid @enderror"
                                        name="external_party_id" required>
                                    <option value="">— اختر المقاول —</option>
                                    @foreach($parties as $party)
                                        <option value="{{ $party->id }}"
                                                @selected(old('external_party_id') == $party->id)>
                                            {{ $party->name }}
                                            @if($party->cr_number)
                                                — {{ $party->cr_number }}
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                                @error('external_party_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div class="mt-1">
                                    <small style="color: var(--text-muted);">
                                        لا تجد المقاول؟
                                        <a href="#" data-bs-toggle="modal" data-bs-target="#quickPartyModal"
                                           style="color: var(--accent-color);">أضفه الآن</a>
                                    </small>
                                </div>
                            </div>

                            {{-- Role --}}
                            <div class="col-md-6">
                                <label class="form-label fw-bold">الدور <span class="text-danger">*</span></label>
                                <select class="form-select @error('role') is-invalid @enderror"
                                        name="role" required>
                                    @foreach($roles as $value => $label)
                                        <option value="{{ $value }}" @selected(old('role', 'main') == $value)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('role')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Activity scope description --}}
                            <div class="col-md-12">
                                <label class="form-label fw-bold">نطاق الأعمال في هذا المشروع</label>
                                <textarea class="form-control @error('activity_scope') is-invalid @enderror"
                                          name="activity_scope" rows="2"
                                          placeholder="مثال: تركيب وتوصيل الأنظمة الكهربائية في المباني من الطابق 1 إلى 10">{{ old('activity_scope') }}</textarea>
                                @error('activity_scope')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Contract dates --}}
                            <div class="col-md-6">
                                <label class="form-label">تاريخ بداية العقد</label>
                                <input type="date" class="form-control @error('contract_start_date') is-invalid @enderror"
                                       name="contract_start_date" value="{{ old('contract_start_date') }}">
                                @error('contract_start_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">تاريخ انتهاء العقد</label>
                                <input type="date" class="form-control @error('contract_end_date') is-invalid @enderror"
                                       name="contract_end_date" value="{{ old('contract_end_date') }}">
                                @error('contract_end_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Notes --}}
                            <div class="col-md-12">
                                <label class="form-label">ملاحظات</label>
                                <textarea class="form-control @error('notes') is-invalid @enderror"
                                          name="notes" rows="2">{{ old('notes') }}</textarea>
                                @error('notes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <a href="{{ route('projects.contractors', $project) }}"
                               class="btn btn-outline-secondary">إلغاء</a>
                            <button type="submit" class="btn btn-accent">
                                <i class="bi bi-person-check me-1"></i>
                                ربط بالمشروع
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
{{-- Quick Create Party Modal --}}
<div class="modal fade" id="quickPartyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background: var(--bg-card); color: var(--text-main);">
            <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                <h6 class="modal-title fw-bold"><i class="bi bi-person-plus me-1"></i> إضافة مقاول جديد</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">اسم المقاول / الشركة <span class="text-danger">*</span></label>
                    <input type="text" id="qpName" class="form-control" placeholder="مثال: شركة الإنشاءات الحديثة">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">نوع الطرف <span class="text-danger">*</span></label>
                    <select id="qpType" class="form-select">
                        <option value="contractor">مقاول</option>
                        <option value="service_provider">مزود خدمة</option>
                        <option value="supplier">مورّد</option>
                        <option value="consultant">استشاري</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">رقم السجل التجاري</label>
                    <input type="text" id="qpCr" class="form-control" placeholder="اختياري">
                </div>
                <div id="qpError" class="alert alert-danger d-none"></div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" id="qpSaveBtn" class="btn btn-accent">
                    <i class="bi bi-check-lg me-1"></i> حفظ وإضافة للقائمة
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {

    // Quick create party
    document.getElementById('qpSaveBtn').addEventListener('click', function () {
        const btn     = this;
        const name    = document.getElementById('qpName').value.trim();
        const type    = document.getElementById('qpType').value;
        const cr      = document.getElementById('qpCr').value.trim();
        const errBox  = document.getElementById('qpError');

        if (!name) { errBox.textContent = 'الاسم مطلوب.'; errBox.classList.remove('d-none'); return; }
        errBox.classList.add('d-none');

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> جاري الحفظ...';

        fetch('{{ route('projects.contractors.quick-party', $project) }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ name, party_type: type, cr_number: cr || null }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.id) {
                const sel = document.querySelector('select[name="external_party_id"]');
                const opt = document.createElement('option');
                opt.value = data.id;
                opt.textContent = data.name + (data.cr_number ? ' — ' + data.cr_number : '');
                opt.selected = true;
                sel.appendChild(opt);
                bootstrap.Modal.getInstance(document.getElementById('quickPartyModal')).hide();
                document.getElementById('qpName').value = '';
                document.getElementById('qpCr').value   = '';
            } else {
                errBox.textContent = data.message || 'حدث خطأ.';
                errBox.classList.remove('d-none');
            }
        })
        .catch(() => { errBox.textContent = 'خطأ في الاتصال.'; errBox.classList.remove('d-none'); })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> حفظ وإضافة للقائمة';
        });
    });

});
</script>
@endpush
