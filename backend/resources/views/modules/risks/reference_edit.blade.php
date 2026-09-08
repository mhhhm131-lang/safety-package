@extends('layouts.app')

@section('page_title', 'تعديل الخطر المرجعي')

@php
    use App\Modules\Risk\Models\RiskPhase;

    $phaseMeta = [
        RiskPhase::PHASE_PROACTIVE   => ['label' => 'استباقي',  'hint' => 'قبل بدء العمل — الوقاية والتحضير',   'icon' => 'bi-shield-plus',      'color' => '#3b82f6'],
        RiskPhase::PHASE_OPERATIONAL => ['label' => 'تشغيلي',   'hint' => 'أثناء العمل — الضوابط والرقابة',      'icon' => 'bi-lightning-charge', 'color' => '#f59e0b'],
        RiskPhase::PHASE_RESPONSE    => ['label' => 'استجابة',  'hint' => 'بعد وقوع الحادث — التعامل والتحقيق',  'icon' => 'bi-bandaid',          'color' => '#ef4444'],
    ];

    $phasesByKey = $risk->phases->keyBy('phase');
@endphp

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1" style="color:var(--text-main);">
                <i class="bi bi-pencil-square me-2" style="color:var(--accent);"></i>تعديل الخطر المرجعي
            </h4>
            <small style="color:var(--text-muted);">
                {{ $risk->title }}
                @if($risk->code) <span class="badge bg-secondary ms-1">{{ $risk->code }}</span> @endif
            </small>
        </div>
        <a href="{{ route('risk.reference.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-right me-1"></i> رجوع</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('risk.reference.update', $risk) }}">
        @csrf

        {{-- 1. التصنيف --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-tags me-2"></i>التصنيف</h6>
                <small style="color:var(--text-muted);">موقع الخطر في التسلسل الهرمي</small>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">الفئة الرئيسية <span class="text-danger">*</span></label>
                        <select name="category_id" id="catSelect" class="form-select" required>
                            <option value="">اختر...</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" @selected(old('category_id', $risk->category_id) == $cat->id)>{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">الفئة الفرعية</label>
                        <select name="sub_category_id" id="subCatSelect" class="form-select">
                            <option value="">اختر الفئة أولاً...</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">نوع الخطر <small class="text-muted">(المستوى الثالث)</small></label>
                        <select name="risk_type_category_id" id="riskTypeSelect" class="form-select">
                            <option value="">اختر الفئة الفرعية أولاً...</option>
                        </select>
                        <small style="color:var(--text-muted);">يصبح اسم الخطر تلقائياً</small>
                    </div>
                </div>
            </div>
        </div>

        {{-- 2. التقييم الأولي --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-speedometer2 me-2"></i>التقييم الأولي</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">الخطورة <span class="text-danger">*</span></label>
                        <select name="severity" id="sevSelect" class="form-select" required onchange="updateScore()">
                            @for($i=1;$i<=5;$i++)
                                <option value="{{$i}}" @selected(old('severity', $risk->severity)==$i)>{{$i}} — {{ ['','طفيف','بسيط','متوسط','كبير','كارثي'][$i] }}</option>
                            @endfor
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">الاحتمالية <span class="text-danger">*</span></label>
                        <select name="likelihood" id="likSelect" class="form-select" required onchange="updateScore()">
                            @for($i=1;$i<=5;$i++)
                                <option value="{{$i}}" @selected(old('likelihood', $risk->likelihood)==$i)>{{$i}} — {{ ['','نادر','غير مرجح','ممكن','مرجح','شبه مؤكد'][$i] }}</option>
                            @endfor
                        </select>
                    </div>
                    <div class="col-md-4 text-center">
                        <label class="form-label" style="color:var(--text-main);">درجة الخطر</label>
                        <div><span id="scoreBadge" class="badge fs-5 px-3 py-2 bg-secondary">{{ $risk->risk_score ?? 1 }}</span></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="color:var(--text-main);"><i class="bi bi-book-half me-1"></i>المرجع القانوني</label>
                        <input type="text" name="legal_reference" class="form-control"
                               placeholder="مثال: ISO 45001:2018 §6.1.2"
                               value="{{ old('legal_reference', $risk->legal_reference) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="color:var(--text-main);">الفائدة من التوثيق</label>
                        <input type="text" name="benefit" class="form-control" value="{{ old('benefit', $risk->benefit) }}">
                    </div>
                </div>
            </div>
        </div>

        {{-- 3. النطاق التنظيمي --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-geo-alt me-2"></i>النطاق التنظيمي</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">نطاق تطبيق الخطر</label>
                        <select name="scope_type" id="scopeType" class="form-select"
                                onchange="document.getElementById('orgUnitDiv').style.display=this.value==='org_unit'?'block':'none'">
                            <option value="general" @selected(old('scope_type', $risk->scope_type ?? 'general')==='general')>عام — المؤسسة بالكامل</option>
                            <option value="org_unit" @selected(old('scope_type', $risk->scope_type)==='org_unit')>وحدة تنظيمية محددة</option>
                        </select>
                    </div>
                    <div class="col-md-4" id="orgUnitDiv"
                         style="display:{{ old('scope_type', $risk->scope_type)==='org_unit'?'block':'none' }};">
                        <label class="form-label" style="color:var(--text-main);">الوحدة التنظيمية</label>
                        <select name="organization_unit_id" class="form-select">
                            <option value="">اختر...</option>
                            @foreach($orgUnits as $unit)
                                <option value="{{ $unit->id }}" @selected(old('organization_unit_id', $risk->organization_unit_id)==$unit->id)>{{ $unit->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">المكان (المعهد)</label>
                        <select name="place_id" class="form-select">
                            <option value="">—</option>
                            @foreach($places as $place)
                                <option value="{{ $place->id }}" @selected(old('place_id', $risk->place_id ?? null) == $place->id)>{{ $place->code }} — {{ $place->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>

        {{-- 4. المراحل الثلاث --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-layers me-2"></i>مراحل الخطر</h6>
                <small style="color:var(--text-muted);">لكل مرحلة: أسباب + متأثرون + إجراءات + مسؤولون</small>
            </div>
            <div class="card-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    @foreach($phaseMeta as $phaseKey => $meta)
                    <li class="nav-item">
                        <button class="nav-link @if($loop->first)active @endif" type="button"
                                data-bs-toggle="tab" data-bs-target="#phase-{{ $phaseKey }}" role="tab"
                                style="color:{{ $meta['color'] }};font-weight:600;">
                            <i class="bi {{ $meta['icon'] }} me-1"></i>{{ $meta['label'] }}
                        </button>
                    </li>
                    @endforeach
                </ul>

                <div class="tab-content">
                    @foreach($phaseMeta as $phaseKey => $meta)
                        @php
                            $phase       = $phasesByKey->get($phaseKey);
                            $phaseCauses = $phase ? $phase->causes->pluck('name')->all() : [];
                            $phaseGroupIds = $phase ? $phase->affectedGroups->pluck('id')->all() : [];
                            $phaseDetails  = $phase ? $phase->affectedGroupDetails->keyBy('affected_group_id') : collect();
                        @endphp
                        <div class="tab-pane @if($loop->first)show active @endif" id="phase-{{ $phaseKey }}" role="tabpanel">
                            <div class="alert py-2 mb-3" style="background:{{ $meta['color'] }}15;border-inline-start:3px solid {{ $meta['color'] }};color:var(--text-main);">
                                <i class="bi {{ $meta['icon'] }} me-2"></i>{{ $meta['hint'] }}
                            </div>

                            {{-- الأسباب --}}
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label mb-0" style="color:var(--text-main);"><i class="bi bi-exclamation-circle me-1"></i>الأسباب</label>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addCauseRow('{{ $phaseKey }}')">
                                        <i class="bi bi-plus-lg me-1"></i>إضافة سبب
                                    </button>
                                </div>
                                <div id="causes-{{ $phaseKey }}">
                                    @forelse($phaseCauses as $causeName)
                                        <div class="input-group input-group-sm mb-2">
                                            <input type="text" name="phases[{{ $phaseKey }}][cause_names][]" class="form-control" value="{{ $causeName }}">
                                            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()"><i class="bi bi-trash"></i></button>
                                        </div>
                                    @empty
                                        <div class="input-group input-group-sm mb-2">
                                            <input type="text" name="phases[{{ $phaseKey }}][cause_names][]" class="form-control" placeholder="اسم السبب...">
                                            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()"><i class="bi bi-trash"></i></button>
                                        </div>
                                    @endforelse
                                </div>
                            </div>

                            {{-- المتأثرون --}}
                            <div class="mb-3">
                                <label class="form-label" style="color:var(--text-main);"><i class="bi bi-people me-1"></i>المجموعات المتأثرة</label>
                                @foreach($masterAndTenantGroups as $group)
                                    @php
                                        $gid       = $group->id;
                                        $isChecked = in_array($gid, $phaseGroupIds, true);
                                        $detail    = $phaseDetails->get($gid);
                                        $impact    = old("phases.{$phaseKey}.affected_impact.{$gid}", $detail->impact ?? 'medium');
                                        $repScope  = old("phases.{$phaseKey}.affected_rep_scope.{$gid}", $detail->rep_scope ?? '');
                                    @endphp
                                    <div class="d-flex align-items-center gap-2 p-2 mb-2 rounded" style="background:var(--bg-main);">
                                        <input class="form-check-input" type="checkbox"
                                               name="phases[{{ $phaseKey }}][affected_group_ids][]"
                                               value="{{ $gid }}" @checked($isChecked) id="rag_{{ $phaseKey }}_{{ $gid }}">
                                        <label for="rag_{{ $phaseKey }}_{{ $gid }}" style="color:var(--text-main);min-width:120px;font-weight:600;">{{ $group->name }}</label>
                                        <select name="phases[{{ $phaseKey }}][affected_impact][{{ $gid }}]" class="form-select form-select-sm" style="max-width:130px;">
                                            <option value="low" @selected($impact==='low')>منخفض</option>
                                            <option value="medium" @selected($impact==='medium')>متوسط</option>
                                            <option value="high" @selected($impact==='high')>عالي</option>
                                            <option value="critical" @selected($impact==='critical')>حرج</option>
                                        </select>
                                        <select name="phases[{{ $phaseKey }}][affected_rep_scope][{{ $gid }}]" class="form-select form-select-sm" style="max-width:130px;">
                                            <option value="" @selected($repScope==='')>— النطاق —</option>
                                            <option value="local" @selected($repScope==='local')>محلي</option>
                                            <option value="regional" @selected($repScope==='regional')>إقليمي</option>
                                            <option value="national" @selected($repScope==='national')>وطني</option>
                                            <option value="international" @selected($repScope==='international')>دولي</option>
                                        </select>
                                    </div>
                                @endforeach
                            </div>

                            {{-- الإجراءات --}}
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

                            {{-- التقييم المتبقي --}}
                            <div class="mb-3">
                                <label class="form-label" style="color:var(--text-main);"><i class="bi bi-clipboard2-check me-1"></i>التقييم بعد الإجراءات (الخطر المتبقي)</label>
                                <textarea name="phases[{{ $phaseKey }}][residual_assessment]" class="form-control" rows="2"
                                          placeholder="ما مستوى الخطر المتبقي بعد تطبيق الإجراءات؟">{{ old("phases.{$phaseKey}.residual_assessment", $phase?->residual_assessment) }}</textarea>
                            </div>

                            {{-- الجهة والشخص --}}
                            <div class="row g-3 p-2 rounded" style="background:var(--bg-main);">
                                <div class="col-12 mb-1"><strong style="color:var(--text-main);font-size:0.85rem;"><i class="bi bi-person-badge me-1"></i>الجهة والشخص المسؤول</strong></div>
                                <div class="col-md-6">
                                    <label class="form-label small" style="color:var(--text-muted);">الجهة (من الهيكل)</label>
                                    <select name="phases[{{ $phaseKey }}][responsible_org_unit_id]" class="form-select form-select-sm">
                                        <option value="">— اختر —</option>
                                        @foreach($orgUnits as $unit)
                                            <option value="{{ $unit->id }}" @selected(old("phases.{$phaseKey}.responsible_org_unit_id", $phase?->responsible_org_unit_id)==$unit->id)>{{ $unit->name }}</option>
                                        @endforeach
                                    </select>
                                    <input type="text" name="phases[{{ $phaseKey }}][responsible_org_unit_text]"
                                           class="form-control form-control-sm mt-1" placeholder="أو نص حر..."
                                           value="{{ old("phases.{$phaseKey}.responsible_org_unit_text", $phase?->responsible_org_unit_text) }}">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small" style="color:var(--text-muted);">الشخص المسؤول</label>
                                    <select name="phases[{{ $phaseKey }}][responsible_user_id]" class="form-select form-select-sm">
                                        <option value="">— اختر —</option>
                                        @foreach($tenantUsers as $u)
                                            <option value="{{ $u->id }}" @selected(old("phases.{$phaseKey}.responsible_user_id", $phase?->responsible_user_id)==$u->id)>{{ $u->name }}</option>
                                        @endforeach
                                    </select>
                                    <input type="text" name="phases[{{ $phaseKey }}][responsible_user_text]"
                                           class="form-control form-control-sm mt-1" placeholder="أو نص حر..."
                                           value="{{ old("phases.{$phaseKey}.responsible_user_text", $phase?->responsible_user_text) }}">
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-lg px-5"><i class="bi bi-check-lg me-2"></i>حفظ التعديلات</button>
            <a href="{{ route('risk.reference.index') }}" class="btn btn-outline-secondary btn-lg px-4">إلغاء</a>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
const initialSubCategoryId  = @json($risk->sub_category_id);
const initialRiskTypeId     = @json($risk->risk_type_category_id);

function updateScore() {
    const s = parseInt(document.getElementById('sevSelect').value) || 1;
    const l = parseInt(document.getElementById('likSelect').value) || 1;
    const score = s * l;
    const badge = document.getElementById('scoreBadge');
    badge.textContent = score;
    badge.className = 'badge fs-5 px-3 py-2 ' + (score >= 15 ? 'bg-danger' : score >= 8 ? 'bg-warning text-dark' : 'bg-success');
}

function loadSubCats(catId, selSubId = null) {
    const sub = document.getElementById('subCatSelect');
    document.getElementById('riskTypeSelect').innerHTML = '<option value="">اختر الفئة الفرعية أولاً...</option>';
    if (!catId) { sub.innerHTML = '<option value="">اختر الفئة أولاً...</option>'; return; }
    fetch('/app/risk/ajax/subcategories?category_id=' + catId)
        .then(r => r.json())
        .then(data => {
            sub.innerHTML = '<option value="">اختر الفرعية...</option>';
            data.forEach(s => {
                const o = document.createElement('option');
                o.value = s.id; o.textContent = s.name;
                if (selSubId && String(s.id) === String(selSubId)) o.selected = true;
                sub.appendChild(o);
            });
            if (selSubId) loadRiskTypes(selSubId, initialRiskTypeId);
        }).catch(() => sub.innerHTML = '<option value="">غير متاح</option>');
}

function loadRiskTypes(subCatId, selTypeId = null) {
    const sel = document.getElementById('riskTypeSelect');
    if (!subCatId) { sel.innerHTML = '<option value="">اختر الفئة الفرعية أولاً...</option>'; return; }
    fetch('/app/risk/ajax/causes?sub_category_id=' + subCatId)
        .then(r => r.json())
        .then(data => {
            sel.innerHTML = '<option value="">— اختر نوع الخطر —</option>';
            data.forEach(t => {
                const o = document.createElement('option');
                o.value = t.id; o.textContent = t.name;
                if (selTypeId && String(t.id) === String(selTypeId)) o.selected = true;
                sel.appendChild(o);
            });
        }).catch(() => sel.innerHTML = '<option value="">غير متاح</option>');
}

document.getElementById('catSelect').addEventListener('change', function() {
    loadSubCats(this.value, null);
});
document.getElementById('subCatSelect').addEventListener('change', function() {
    loadRiskTypes(this.value, null);
});

// Load initial cascading values
if (document.getElementById('catSelect').value) {
    loadSubCats(document.getElementById('catSelect').value, initialSubCategoryId);
}

function addCauseRow(phaseKey) {
    document.getElementById('causes-' + phaseKey).insertAdjacentHTML('beforeend', `
        <div class="input-group input-group-sm mb-2">
            <input type="text" name="phases[${phaseKey}][cause_names][]" class="form-control" placeholder="اسم السبب...">
            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()"><i class="bi bi-trash"></i></button>
        </div>`);
}

updateScore();
</script>
@endpush
