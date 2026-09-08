@extends('layouts.app')

@section('page_title', 'تعديل الخطر')

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-10">

            {{-- Page Header --}}
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--text-main);">
                        <i class="bi bi-pencil-square me-2"></i>تعديل الخطر
                    </h4>
                    <p class="mb-0" style="color: var(--text-muted);">
                        تعديل بيانات الخطر: <strong style="color: var(--text-main);">{{ $risk->title }}</strong>
                        @if($risk->code)
                            <span class="badge bg-secondary ms-2">{{ $risk->code }}</span>
                        @endif
                    </p>
                </div>
                <a href="{{ route('risk.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-right me-1"></i> العودة للقائمة
                </a>
            </div>

            {{-- Validation Errors --}}
            @if($errors->any())
                <div class="alert alert-danger border-0 shadow-sm">
                    <div class="d-flex align-items-center mb-2">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>يرجى تصحيح الأخطاء التالية:</strong>
                    </div>
                    <ul class="mb-0 pe-3">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger border-0 shadow-sm">
                    <i class="bi bi-x-circle me-2"></i>{{ session('error') }}
                </div>
            @endif

            <form method="POST" action="{{ route('risk.update', $risk) }}" id="riskForm">
                @csrf

                {{-- Section 1: Basic Information --}}
                <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                    <div class="card-header d-flex align-items-center" style="background: var(--bg-dark); border-bottom: 1px solid var(--border-color);">
                        <i class="bi bi-info-circle me-2" style="color: var(--accent-color, #0891b2);"></i>
                        <h6 class="mb-0 fw-bold" style="color: var(--text-main);">المعلومات الأساسية</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="title" class="form-label fw-semibold" style="color: var(--text-main);">
                                    عنوان الخطر <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control @error('title') is-invalid @enderror"
                                       id="title" name="title" value="{{ old('title', $risk->title) }}" required
                                       placeholder="أدخل عنوان الخطر..."
                                       style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">
                                @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-12">
                                <label for="description" class="form-label fw-semibold" style="color: var(--text-main);">
                                    وصف الخطر <span class="text-danger">*</span>
                                </label>
                                <textarea class="form-control @error('description') is-invalid @enderror"
                                          id="description" name="description" rows="4" required
                                          placeholder="أدخل وصف تفصيلي للخطر..."
                                          style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">{{ old('description', $risk->description) }}</textarea>
                                @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="category_id" class="form-label fw-semibold" style="color: var(--text-main);">
                                    الفئة الرئيسية <span class="text-danger">*</span>
                                </label>
                                <select class="form-select @error('category_id') is-invalid @enderror"
                                        id="category_id" name="category_id" required
                                        style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">
                                    <option value="">اختر الفئة...</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}"
                                                @selected(old('category_id', $risk->category_id) == $category->id)>
                                            {{ $category->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('category_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="sub_category_id" class="form-label fw-semibold" style="color: var(--text-main);">
                                    الفئة الفرعية
                                </label>
                                <select class="form-select @error('sub_category_id') is-invalid @enderror"
                                        id="sub_category_id" name="sub_category_id"
                                        style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">
                                    <option value="">اختر الفئة الفرعية...</option>
                                </select>
                                @error('sub_category_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Section 2: Risk Assessment --}}
                <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                    <div class="card-header d-flex align-items-center" style="background: var(--bg-dark); border-bottom: 1px solid var(--border-color);">
                        <i class="bi bi-speedometer2 me-2" style="color: var(--accent-color, #0891b2);"></i>
                        <h6 class="mb-0 fw-bold" style="color: var(--text-main);">تقييم الخطر</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="severity" class="form-label fw-semibold" style="color: var(--text-main);">
                                    الخطورة <span class="text-danger">*</span>
                                </label>
                                <select class="form-select @error('severity') is-invalid @enderror"
                                        id="severity" name="severity" required
                                        style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">
                                    <option value="">اختر...</option>
                                    <option value="1" @selected(old('severity', $risk->severity) == 1)>1 - ضئيل</option>
                                    <option value="2" @selected(old('severity', $risk->severity) == 2)>2 - بسيط</option>
                                    <option value="3" @selected(old('severity', $risk->severity) == 3)>3 - متوسط</option>
                                    <option value="4" @selected(old('severity', $risk->severity) == 4)>4 - كبير</option>
                                    <option value="5" @selected(old('severity', $risk->severity) == 5)>5 - كارثي</option>
                                </select>
                                @error('severity') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-4">
                                <label for="likelihood" class="form-label fw-semibold" style="color: var(--text-main);">
                                    الاحتمالية <span class="text-danger">*</span>
                                </label>
                                <select class="form-select @error('likelihood') is-invalid @enderror"
                                        id="likelihood" name="likelihood" required
                                        style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">
                                    <option value="">اختر...</option>
                                    <option value="1" @selected(old('likelihood', $risk->likelihood) == 1)>1 - نادر</option>
                                    <option value="2" @selected(old('likelihood', $risk->likelihood) == 2)>2 - غير محتمل</option>
                                    <option value="3" @selected(old('likelihood', $risk->likelihood) == 3)>3 - ممكن</option>
                                    <option value="4" @selected(old('likelihood', $risk->likelihood) == 4)>4 - محتمل</option>
                                    <option value="5" @selected(old('likelihood', $risk->likelihood) == 5)>5 - شبه مؤكد</option>
                                </select>
                                @error('likelihood') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold" style="color: var(--text-main);">درجة الخطر</label>
                                <div class="p-3 rounded text-center" style="background: var(--bg-dark); border: 1px solid var(--border-color);">
                                    <span id="riskScore" class="badge fs-5 bg-secondary">0</span>
                                </div>
                            </div>

                            {{-- 5x5 Risk Matrix --}}
                            <div class="col-12">
                                <label class="form-label fw-semibold" style="color: var(--text-main);">مصفوفة الخطر 5x5</label>
                                <div class="table-responsive">
                                    <table class="table table-bordered text-center mb-0" style="border-color: var(--border-color);" id="riskMatrix">
                                        <thead>
                                            <tr>
                                                <th style="background: var(--bg-dark); color: var(--text-muted); border-color: var(--border-color); width: 120px;">الخطورة \ الاحتمالية</th>
                                                <th style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">1 - نادر</th>
                                                <th style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">2 - غير محتمل</th>
                                                <th style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">3 - ممكن</th>
                                                <th style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">4 - محتمل</th>
                                                <th style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">5 - شبه مؤكد</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @for($s = 5; $s >= 1; $s--)
                                                <tr>
                                                    <td style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color); font-weight: 600;">{{ $s }} - {{ ['', 'ضئيل', 'بسيط', 'متوسط', 'كبير', 'كارثي'][$s] }}</td>
                                                    @for($l = 1; $l <= 5; $l++)
                                                        @php $sc = $s * $l; $bg = $sc >= 15 ? '#dc3545' : ($sc >= 8 ? '#fd7e14' : ($sc >= 4 ? '#ffc107' : '#198754')); @endphp
                                                        <td class="matrix-cell" data-s="{{ $s }}" data-l="{{ $l }}" style="background: {{ $bg }}20; color: {{ $bg }}; border-color: var(--border-color); font-weight: 700; cursor: pointer; transition: all 0.2s;">{{ $sc }}</td>
                                                    @endfor
                                                </tr>
                                            @endfor
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Section 3: Actions & Measures --}}
                <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                    <div class="card-header d-flex align-items-center" style="background: var(--bg-dark); border-bottom: 1px solid var(--border-color);">
                        <i class="bi bi-shield-check me-2" style="color: var(--accent-color, #0891b2);"></i>
                        <h6 class="mb-0 fw-bold" style="color: var(--text-main);">الإجراءات والتدابير</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="corrective_action" class="form-label fw-semibold" style="color: var(--text-main);">الإجراء التصحيحي</label>
                                <textarea class="form-control @error('corrective_action') is-invalid @enderror" id="corrective_action" name="corrective_action" rows="3" placeholder="وصف الإجراء التصحيحي..." style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">{{ old('corrective_action', $risk->corrective_action) }}</textarea>
                                @error('corrective_action') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="preventive_action" class="form-label fw-semibold" style="color: var(--text-main);">الإجراء الوقائي</label>
                                <textarea class="form-control @error('preventive_action') is-invalid @enderror" id="preventive_action" name="preventive_action" rows="3" placeholder="وصف الإجراء الوقائي..." style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">{{ old('preventive_action', $risk->preventive_action) }}</textarea>
                                @error('preventive_action') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-12">
                                <label for="benefit" class="form-label fw-semibold" style="color: var(--text-main);">الفائدة / العائد</label>
                                <textarea class="form-control @error('benefit') is-invalid @enderror" id="benefit" name="benefit" rows="2" placeholder="وصف الفائدة المتوقعة من معالجة الخطر..." style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">{{ old('benefit', $risk->benefit) }}</textarea>
                                @error('benefit') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Section 4: Ownership & Timeline --}}
                <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                    <div class="card-header d-flex align-items-center" style="background: var(--bg-dark); border-bottom: 1px solid var(--border-color);">
                        <i class="bi bi-person-badge me-2" style="color: var(--accent-color, #0891b2);"></i>
                        <h6 class="mb-0 fw-bold" style="color: var(--text-main);">المسؤولية والجدول الزمني</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="owner_department" class="form-label fw-semibold" style="color: var(--text-main);">القسم المسؤول</label>
                                <input type="text" class="form-control @error('owner_department') is-invalid @enderror" id="owner_department" name="owner_department" value="{{ old('owner_department', $risk->owner_department) }}" placeholder="اسم القسم المسؤول..." style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">
                                @error('owner_department') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="owner_person" class="form-label fw-semibold" style="color: var(--text-main);">الشخص المسؤول</label>
                                <input type="text" class="form-control @error('owner_person') is-invalid @enderror" id="owner_person" name="owner_person" value="{{ old('owner_person', $risk->owner_person) }}" placeholder="اسم الشخص المسؤول..." style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">
                                @error('owner_person') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="organization_unit_id" class="form-label fw-semibold" style="color: var(--text-main);">الوحدة التنظيمية</label>
                                <select class="form-select @error('organization_unit_id') is-invalid @enderror" id="organization_unit_id" name="organization_unit_id" style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">
                                    <option value="">اختر الوحدة التنظيمية...</option>
                                    @foreach($orgUnits ?? [] as $unit)
                                        <option value="{{ $unit->id }}" @selected(old('organization_unit_id', $risk->organization_unit_id) == $unit->id)>{{ $unit->name }}</option>
                                    @endforeach
                                </select>
                                @error('organization_unit_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="target_closure_date" class="form-label fw-semibold" style="color: var(--text-main);">تاريخ الإغلاق المستهدف</label>
                                <input type="date" class="form-control @error('target_closure_date') is-invalid @enderror" id="target_closure_date" name="target_closure_date" value="{{ old('target_closure_date', $risk->target_closure_date?->format('Y-m-d')) }}" style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">
                                @error('target_closure_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Section 5: Causes & Affected Groups --}}
                <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                    <div class="card-header d-flex align-items-center" style="background: var(--bg-dark); border-bottom: 1px solid var(--border-color);">
                        <i class="bi bi-diagram-3 me-2" style="color: var(--accent-color, #0891b2);"></i>
                        <h6 class="mb-0 fw-bold" style="color: var(--text-main);">الأسباب والفئات المتأثرة</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" style="color: var(--text-main);">الأسباب</label>
                                <div id="causesContainer">
                                    @if(old('causes'))
                                        @foreach(old('causes') as $cause)
                                            <div class="input-group mb-2">
                                                <input type="text" name="causes[]" class="form-control" value="{{ $cause }}" placeholder="أدخل السبب..." style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">
                                                <button type="button" class="btn btn-outline-danger remove-cause"><i class="bi bi-trash"></i></button>
                                            </div>
                                        @endforeach
                                    @elseif($risk->causes && $risk->causes->count())
                                        @foreach($risk->causes as $cause)
                                            <div class="input-group mb-2">
                                                <input type="text" name="causes[]" class="form-control" value="{{ $cause->name }}" placeholder="أدخل السبب..." style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">
                                                <button type="button" class="btn btn-outline-danger remove-cause"><i class="bi bi-trash"></i></button>
                                            </div>
                                        @endforeach
                                    @else
                                        <div class="input-group mb-2">
                                            <input type="text" name="causes[]" class="form-control" placeholder="أدخل السبب..." style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">
                                            <button type="button" class="btn btn-outline-danger remove-cause" style="display:none;"><i class="bi bi-trash"></i></button>
                                        </div>
                                    @endif
                                </div>
                                <button type="button" id="addCause" class="btn btn-sm btn-outline-primary mt-1"><i class="bi bi-plus me-1"></i> إضافة سبب</button>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" style="color: var(--text-main);">الفئات المتأثرة</label>
                                <div id="affectedGroupsContainer">
                                    @if(old('affected_groups'))
                                        @foreach(old('affected_groups') as $group)
                                            <div class="input-group mb-2">
                                                <input type="text" name="affected_groups[]" class="form-control" value="{{ $group }}" placeholder="أدخل الفئة المتأثرة..." style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">
                                                <button type="button" class="btn btn-outline-danger remove-group"><i class="bi bi-trash"></i></button>
                                            </div>
                                        @endforeach
                                    @elseif($risk->affectedGroups && $risk->affectedGroups->count())
                                        @foreach($risk->affectedGroups as $group)
                                            <div class="input-group mb-2">
                                                <input type="text" name="affected_groups[]" class="form-control" value="{{ $group->name }}" placeholder="أدخل الفئة المتأثرة..." style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">
                                                <button type="button" class="btn btn-outline-danger remove-group"><i class="bi bi-trash"></i></button>
                                            </div>
                                        @endforeach
                                    @else
                                        <div class="input-group mb-2">
                                            <input type="text" name="affected_groups[]" class="form-control" placeholder="أدخل الفئة المتأثرة..." style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);">
                                            <button type="button" class="btn btn-outline-danger remove-group" style="display:none;"><i class="bi bi-trash"></i></button>
                                        </div>
                                    @endif
                                </div>
                                <button type="button" id="addGroup" class="btn btn-sm btn-outline-primary mt-1"><i class="bi bi-plus me-1"></i> إضافة فئة</button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Submit --}}
                <div class="d-flex gap-2 mb-4">
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-circle me-1"></i> تحديث الخطر</button>
                    <a href="{{ route('risk.index') }}" class="btn btn-outline-secondary px-4"><i class="bi bi-x-circle me-1"></i> إلغاء</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const severity = document.getElementById('severity');
    const likelihood = document.getElementById('likelihood');

    function updateScore() {
        const s = parseInt(severity.value) || 0;
        const l = parseInt(likelihood.value) || 0;
        const score = s * l;
        const el = document.getElementById('riskScore');
        el.textContent = score;
        el.className = 'badge fs-5 ' + (score >= 15 ? 'bg-danger' : score >= 8 ? 'bg-warning' : score > 0 ? 'bg-success' : 'bg-secondary');
        document.querySelectorAll('.matrix-cell').forEach(cell => { cell.style.outline = 'none'; cell.style.transform = 'scale(1)'; });
        if (s > 0 && l > 0) {
            const ac = document.querySelector('.matrix-cell[data-s="'+s+'"][data-l="'+l+'"]');
            if (ac) { ac.style.outline = '3px solid var(--text-main)'; ac.style.transform = 'scale(1.1)'; }
        }
    }
    severity.addEventListener('change', updateScore);
    likelihood.addEventListener('change', updateScore);
    updateScore();

    document.querySelectorAll('.matrix-cell').forEach(cell => {
        cell.addEventListener('click', function() { severity.value = this.dataset.s; likelihood.value = this.dataset.l; updateScore(); });
    });

    // AJAX Subcategories
    const catSel = document.getElementById('category_id');
    const subSel = document.getElementById('sub_category_id');
    const initSubId = "{{ old('sub_category_id', $risk->sub_category_id) }}";

    function loadSubs(catId, selId) {
        subSel.innerHTML = '<option value="">جاري التحميل...</option>';
        if (!catId) { subSel.innerHTML = '<option value="">اختر الفئة الفرعية...</option>'; return; }
        fetch("{{ route('risk.ajax.subcategories') }}?category_id=" + catId)
            .then(r => r.json()).then(data => {
                subSel.innerHTML = '<option value="">اختر الفئة الفرعية...</option>';
                data.forEach(s => { const o = document.createElement('option'); o.value = s.id; o.textContent = s.name; if (selId && s.id == selId) o.selected = true; subSel.appendChild(o); });
            }).catch(() => { subSel.innerHTML = '<option value="">اختر الفئة الفرعية...</option>'; });
    }
    catSel.addEventListener('change', function() { loadSubs(this.value, null); });
    if (catSel.value) loadSubs(catSel.value, initSubId);

    // Dynamic causes
    document.getElementById('addCause').addEventListener('click', function() {
        const c = document.getElementById('causesContainer'), d = document.createElement('div');
        d.className = 'input-group mb-2';
        d.innerHTML = '<input type="text" name="causes[]" class="form-control" placeholder="أدخل السبب..." style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);"><button type="button" class="btn btn-outline-danger remove-cause"><i class="bi bi-trash"></i></button>';
        c.appendChild(d); d.querySelector('.remove-cause').addEventListener('click', () => d.remove());
    });
    document.querySelectorAll('.remove-cause').forEach(b => { b.addEventListener('click', function() { this.closest('.input-group').remove(); }); });

    // Dynamic affected groups
    document.getElementById('addGroup').addEventListener('click', function() {
        const c = document.getElementById('affectedGroupsContainer'), d = document.createElement('div');
        d.className = 'input-group mb-2';
        d.innerHTML = '<input type="text" name="affected_groups[]" class="form-control" placeholder="أدخل الفئة المتأثرة..." style="background: var(--bg-dark); color: var(--text-main); border-color: var(--border-color);"><button type="button" class="btn btn-outline-danger remove-group"><i class="bi bi-trash"></i></button>';
        c.appendChild(d); d.querySelector('.remove-group').addEventListener('click', () => d.remove());
    });
    document.querySelectorAll('.remove-group').forEach(b => { b.addEventListener('click', function() { this.closest('.input-group').remove(); }); });
});
</script>
@endpush
