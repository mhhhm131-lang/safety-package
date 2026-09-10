@extends('layouts.app')

@section('page_title', 'تعديل الخطر الرئيسي')

@php
    use App\Modules\Risk\Models\RiskPhase;

    $phaseMeta = [
        RiskPhase::PHASE_PROACTIVE   => ['label' => 'استباقي',  'hint' => 'قبل بدء العمل — الوقاية والتحضير', 'icon' => 'bi-shield-plus',      'color' => '#3b82f6'],
        RiskPhase::PHASE_OPERATIONAL => ['label' => 'تشغيلي',  'hint' => 'أثناء العمل — الضوابط والرقابة',    'icon' => 'bi-lightning-charge', 'color' => '#f59e0b'],
        RiskPhase::PHASE_RESPONSE    => ['label' => 'استجابة', 'hint' => 'بعد وقوع الحادث — التعامل والتحقيق',  'icon' => 'bi-bandaid',          'color' => '#ef4444'],
    ];

    // Index phases by their enum key so the blade can pull the right row per tab.
    $phasesByKey = $risk->phases->keyBy('phase');

    $allMasterAffectedGroups = \App\Modules\Risk\Models\AffectedGroup::all();
@endphp

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1" style="color: var(--text-main);"><i class="bi bi-pencil-square me-2" style="color:var(--accent);"></i>تعديل الخطر الرئيسي</h4>
            <small style="color: var(--text-muted);">
                {{ $risk->title }}
                @if($risk->code) <span class="badge bg-secondary ms-1">{{ $risk->code }}</span> @endif
            </small>
        </div>
        <a href="{{ route('risk.master.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-right me-1"></i> رجوع</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('risk.master.update', $risk) }}">
        @csrf

        {{-- 1. التصنيف --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-tags me-2"></i>التصنيف</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">الفئة الرئيسية <span class="text-danger">*</span></label>
                        <select name="category_id" id="categorySelect" class="form-select" required>
                            <option value="">اختر الفئة...</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" @selected(old('category_id', $risk->category_id) == $cat->id)>{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">الفئة الفرعية</label>
                        <select name="sub_category_id" id="subCategorySelect" class="form-select">
                            <option value="">اختر الفئة أولاً...</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">درجة الخطر</label>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <span id="riskScoreBadge" class="badge bg-secondary fs-5 px-3 py-2">{{ $risk->risk_score }}</span>
                            <small style="color:var(--text-muted);">= الخطورة × الاحتمالية</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 2. بيانات الخطر --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-info-circle me-2"></i>بيانات الخطر</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" style="color:var(--text-main);">عنوان الخطر <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-lg" required value="{{ old('title', $risk->title) }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label" style="color:var(--text-main);">وصف الخطر <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control" rows="3" required>{{ old('description', $risk->description) }}</textarea>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" style="color:var(--text-main);">الخطورة (1-5) <span class="text-danger">*</span></label>
                        <select name="severity" id="severitySelect" class="form-select" required onchange="updateScore()">
                            @for($i=1;$i<=5;$i++)<option value="{{$i}}" @selected(old('severity', $risk->severity) == $i)>{{$i}} — {{ ['','طفيف','بسيط','متوسط','كبير','كارثي'][$i] }}</option>@endfor
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" style="color:var(--text-main);">الاحتمالية (1-5) <span class="text-danger">*</span></label>
                        <select name="likelihood" id="likelihoodSelect" class="form-select" required onchange="updateScore()">
                            @for($i=1;$i<=5;$i++)<option value="{{$i}}" @selected(old('likelihood', $risk->likelihood) == $i)>{{$i}} — {{ ['','نادر','غير مرجح','ممكن','مرجح','شبه مؤكد'][$i] }}</option>@endfor
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="color:var(--text-main);">الفائدة من التوثيق</label>
                        <input type="text" name="benefit" class="form-control" value="{{ old('benefit', $risk->benefit) }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label" style="color:var(--text-main);"><i class="bi bi-book-half me-1"></i>المرجع القانوني</label>
                        <input type="text" name="legal_reference" class="form-control"
                               placeholder="مثال: ISO 45001:2018 §6.1.2 — نظام العمل المادة 121"
                               value="{{ old('legal_reference', $risk->legal_reference) }}">
                        <small style="color:var(--text-muted);">الاشتراطات القانونية أو المعيارية التي تستوجب إدارة هذا الخطر</small>
                    </div>
                </div>
            </div>
        </div>

        {{-- 2.5 نوع الخطر + قناة التواصل --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-diagram-3 me-2"></i>نوع الخطر والتواصل</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" style="color:var(--text-main);">نوع الخطر (المستوى الثالث)</label>
                        <select name="risk_type_category_id" id="typeCatSelect" class="form-select">
                            <option value="">— اختر —</option>
                            @if($risk->risk_type_category_id && $risk->riskTypeCategory)
                                <option value="{{ $risk->risk_type_category_id }}" selected>{{ $risk->riskTypeCategory->name }}</option>
                            @endif
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="color:var(--text-main);">قناة التواصل</label>
                        <input type="text" name="contact_channel" class="form-control" value="{{ old('contact_channel', $risk->contact_channel) }}">
                    </div>
                </div>
            </div>
        </div>

        {{-- ═══════ المراحل الثلاث ═══════ --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-layers me-2"></i>مراحل الخطر</h6>
                <small style="color:var(--text-muted);">بيانات كل مرحلة مستقلة — عدّل كل تبويبة على حدة.</small>
            </div>
            <div class="card-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    @foreach($phaseMeta as $phaseKey => $meta)
                    <li class="nav-item" role="presentation">
                        <button class="nav-link @if($loop->first) active @endif" type="button"
                                data-bs-toggle="tab"
                                data-bs-target="#phase-{{ $phaseKey }}"
                                role="tab"
                                style="color: {{ $meta['color'] }}; font-weight: 600;">
                            <i class="bi {{ $meta['icon'] }} me-1"></i>{{ $meta['label'] }}
                        </button>
                    </li>
                    @endforeach
                </ul>

                <div class="tab-content">
                    @foreach($phaseMeta as $phaseKey => $meta)
                        @php
                            $phase = $phasesByKey->get($phaseKey);
                            $phaseCauses = $phase ? $phase->causes->pluck('name')->all() : [];
                            $phaseGroupIds = $phase ? $phase->affectedGroups->pluck('id')->all() : [];
                            $phaseDetails = $phase ? $phase->affectedGroupDetails->keyBy('affected_group_id') : collect();
                        @endphp
                        <div class="tab-pane @if($loop->first) show active @endif" id="phase-{{ $phaseKey }}" role="tabpanel">
                            <div class="alert py-2 mb-3" style="background: {{ $meta['color'] }}15; border-inline-start: 3px solid {{ $meta['color'] }}; color: var(--text-main);">
                                <i class="bi {{ $meta['icon'] }} me-2"></i>{{ $meta['hint'] }}
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label" style="color:var(--text-main);">الإجراء الوقائي</label>
                                    <textarea name="phases[{{ $phaseKey }}][preventive_action]" class="form-control" rows="3">{{ old("phases.{$phaseKey}.preventive_action", $phase?->preventive_action) }}</textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" style="color:var(--text-main);">الإجراء التصحيحي</label>
                                    <textarea name="phases[{{ $phaseKey }}][corrective_action]" class="form-control" rows="3">{{ old("phases.{$phaseKey}.corrective_action", $phase?->corrective_action) }}</textarea>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" style="color:var(--text-main);"><i class="bi bi-clipboard2-check me-1"></i>التقييم بعد الإجراءات</label>
                                <textarea name="phases[{{ $phaseKey }}][residual_assessment]" class="form-control" rows="2"
                                          placeholder="ما مستوى الخطر المتبقي بعد تطبيق الإجراءات في مرحلة {{ $meta['label'] }}؟">{{ old("phases.{$phaseKey}.residual_assessment", $phase?->residual_assessment) }}</textarea>
                                <small style="color:var(--text-muted);">الخطر المتبقي (Residual Risk) — هل الإجراءات كافية؟ ما الذي لا يزال يحتاج متابعة؟</small>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label" style="color:var(--text-main);">الإدارة المسؤولة <small style="color:var(--text-muted);">(اقتراح نصّي)</small></label>
                                    <input type="text" name="phases[{{ $phaseKey }}][responsible_org_unit_text]" class="form-control"
                                           value="{{ old("phases.{$phaseKey}.responsible_org_unit_text", $phase?->responsible_org_unit_text) }}">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" style="color:var(--text-main);">الشخص المسؤول <small style="color:var(--text-muted);">(اقتراح نصّي)</small></label>
                                    <input type="text" name="phases[{{ $phaseKey }}][responsible_user_text]" class="form-control"
                                           value="{{ old("phases.{$phaseKey}.responsible_user_text", $phase?->responsible_user_text) }}">
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label mb-0" style="color:var(--text-main);"><i class="bi bi-exclamation-circle me-1"></i>الأسباب</label>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addCauseRow('{{ $phaseKey }}')">
                                        <i class="bi bi-plus-lg me-1"></i>إضافة سبب
                                    </button>
                                </div>
                                <div id="causes-{{ $phaseKey }}" class="causes-container">
                                    @forelse($phaseCauses as $causeName)
                                        <div class="input-group input-group-sm mb-2">
                                            <input type="text" name="phases[{{ $phaseKey }}][cause_names][]" class="form-control" value="{{ $causeName }}">
                                            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    @empty
                                        <div class="input-group input-group-sm mb-2">
                                            <input type="text" name="phases[{{ $phaseKey }}][cause_names][]" class="form-control" placeholder="اسم السبب...">
                                            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    @endforelse
                                </div>
                            </div>

                            <div>
                                <label class="form-label" style="color:var(--text-main);"><i class="bi bi-people me-1"></i>المجموعات المتأثرة</label>
                                @foreach($allMasterAffectedGroups as $group)
                                    @php
                                        $gid = $group->id;
                                        $isChecked = in_array($gid, $phaseGroupIds, true);
                                        $existingDetail = $phaseDetails->get($gid);
                                        $impact = $existingDetail->impact ?? 'medium';
                                        $repScope = $existingDetail->rep_scope ?? '';
                                    @endphp
                                    <div class="d-flex align-items-center gap-2 p-2 mb-2 rounded" style="background:var(--bg-main);">
                                        <input class="form-check-input" type="checkbox"
                                               name="phases[{{ $phaseKey }}][affected_group_ids][]"
                                               value="{{ $gid }}" @checked($isChecked)
                                               id="mag_{{ $phaseKey }}_{{ $gid }}">
                                        <label for="mag_{{ $phaseKey }}_{{ $gid }}" style="color:var(--text-main);min-width:120px;font-weight:600;">{{ $group->name }}</label>
                                        <select name="phases[{{ $phaseKey }}][affected_impact][{{ $gid }}]" class="form-select form-select-sm" style="max-width:130px;">
                                            <option value="1" @selected(\App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail::normalizeImpact($impact) === '1')>1 — طفيف</option>
                                            <option value="2" @selected(\App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail::normalizeImpact($impact) === '2')>2 — بسيط</option>
                                            <option value="3" @selected(\App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail::normalizeImpact($impact) === '3')>3 — متوسط</option>
                                            <option value="4" @selected(\App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail::normalizeImpact($impact) === '4')>4 — كبير</option>
                                            <option value="5" @selected(\App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail::normalizeImpact($impact) === '5')>5 — كارثي</option>
                                        </select>
                                        <select name="phases[{{ $phaseKey }}][affected_rep_scope][{{ $gid }}]" class="form-select form-select-sm" style="max-width:130px;">
                                            <option value="" @selected($repScope === '')>— النطاق —</option>
                                            <option value="local" @selected($repScope === 'local')>محلي</option>
                                            <option value="regional" @selected($repScope === 'regional')>إقليمي</option>
                                            <option value="national" @selected($repScope === 'national')>وطني</option>
                                            <option value="international" @selected($repScope === 'international')>دولي</option>
                                        </select>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-lg px-5"><i class="bi bi-check-lg me-2"></i>تحديث الخطر</button>
            <a href="{{ route('risk.master.index') }}" class="btn btn-outline-secondary btn-lg px-4">إلغاء</a>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
const initialSubCategoryId = @json($risk->sub_category_id);

function updateScore() {
    const s = parseInt(document.getElementById('severitySelect').value) || 1;
    const l = parseInt(document.getElementById('likelihoodSelect').value) || 1;
    const score = s * l;
    const badge = document.getElementById('riskScoreBadge');
    badge.textContent = score;
    badge.className = 'badge fs-5 px-3 py-2 ' + (score >= 15 ? 'bg-danger' : score >= 8 ? 'bg-warning' : 'bg-success');
}

function loadSubCategories(catId, selectedId) {
    const sub = document.getElementById('subCategorySelect');
    if (!catId) { sub.innerHTML = '<option value="">اختر الفئة أولاً...</option>'; return; }
    fetch('/app/risk/ajax/subcategories?category_id=' + catId)
        .then(r => r.json())
        .then(data => {
            sub.innerHTML = '<option value="">اختر الفرعية...</option>';
            data.forEach(s => {
                const o = document.createElement('option');
                o.value = s.id; o.textContent = s.name;
                if (selectedId && String(s.id) === String(selectedId)) o.selected = true;
                sub.appendChild(o);
            });
        })
        .catch(() => { sub.innerHTML = '<option value="">غير متاح</option>'; });
}

document.getElementById('categorySelect').addEventListener('change', function() {
    loadSubCategories(this.value, null);
});

// Pre-load sub-category on page open if a category is already selected.
if (document.getElementById('categorySelect').value) {
    loadSubCategories(document.getElementById('categorySelect').value, initialSubCategoryId);
}

function addCauseRow(phaseKey) {
    const container = document.getElementById('causes-' + phaseKey);
    container.insertAdjacentHTML('beforeend', `
        <div class="input-group input-group-sm mb-2">
            <input type="text" name="phases[${phaseKey}][cause_names][]" class="form-control" placeholder="اسم السبب...">
            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()">
                <i class="bi bi-trash"></i>
            </button>
        </div>`);
}

document.getElementById('subCategorySelect')?.addEventListener('change', function() {
    const typeSel = document.getElementById('typeCatSelect');
    if (!this.value) { typeSel.innerHTML = '<option value="">اختر الفرعية أولاً</option>'; return; }
    fetch('/app/risk/ajax/causes?sub_category_id=' + this.value)
        .then(r => r.json())
        .then(data => {
            typeSel.innerHTML = '<option value="">— اختر نوع الخطر —</option>';
            data.forEach(t => typeSel.innerHTML += `<option value="${t.id}">${t.name}</option>`);
        }).catch(() => {});
});

updateScore();
</script>
@endpush
