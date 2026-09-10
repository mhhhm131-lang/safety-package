@extends('layouts.app')

@section('page_title', 'إضافة خطر — السجل المرجعي')

@php
    use App\Modules\Risk\Models\RiskPhase;

    $phaseMeta = [
        RiskPhase::PHASE_PROACTIVE   => ['label' => 'استباقي',  'hint' => 'قبل بدء العمل — الوقاية والتحضير',   'icon' => 'bi-shield-plus',      'color' => '#3b82f6'],
        RiskPhase::PHASE_OPERATIONAL => ['label' => 'تشغيلي',   'hint' => 'أثناء العمل — الضوابط والرقابة',      'icon' => 'bi-lightning-charge', 'color' => '#f59e0b'],
        RiskPhase::PHASE_RESPONSE    => ['label' => 'استجابة',  'hint' => 'بعد وقوع الحادث — التعامل والتحقيق',  'icon' => 'bi-bandaid',          'color' => '#ef4444'],
    ];
@endphp

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1" style="color:var(--text-main);"><i class="bi bi-bookmark-plus me-2" style="color:var(--accent);"></i>إضافة خطر في السجل المرجعي</h4>
            <small style="color:var(--text-muted);">السجل المرجعي للمؤسسة — يُغذّي الإدارات والفروع والأقسام</small>
        </div>
        <a href="{{ route('risk.reference.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-right me-1"></i> رجوع</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('risk.reference.store') }}">
        @csrf

        {{-- 1. التصنيف --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-tags me-2"></i>التصنيف</h6>
                <small style="color:var(--text-muted);">حدد موقع الخطر في التسلسل الهرمي</small>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">الفئة الرئيسية <span class="text-danger">*</span></label>
                        <select name="category_id" id="catSelect" class="form-select" required onchange="loadSubCats(this.value)">
                            <option value="">اختر...</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" @selected(old('category_id') == $cat->id)>{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">الفئة الفرعية</label>
                        <div class="input-group">
                            <select name="sub_category_id" id="subCatSelect" class="form-select" onchange="loadRiskTypes(this.value)">
                                <option value="">اختر الفئة أولاً...</option>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" title="إضافة فئة فرعية جديدة" data-bs-toggle="modal" data-bs-target="#modalAddSubCat">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">نوع الخطر <small class="text-muted">(المستوى الثالث)</small></label>
                        <div class="input-group">
                            <select name="risk_type_category_id" id="riskTypeSelect" class="form-select">
                                <option value="">اختر الفئة الفرعية أولاً...</option>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" title="إضافة نوع جديد" data-bs-toggle="modal" data-bs-target="#modalAddCause">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                        <small style="color:var(--text-muted);">يُستخدم اسماً للخطر إن تُرك «اسم الخطر» فارغاً</small>
                    </div>
                </div>
            </div>
        </div>

        {{-- 1-ب. تعريف الخطر (قرار ٢١: العنوان والوصف حرّان في السجل العام) --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-card-text me-2"></i>تعريف الخطر</h6>
                <small style="color:var(--text-muted);">الاسم = حدث + مصدر؛ الوصف: المصدر ← التعرض ← الحدث ← الموقع</small>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" style="color:var(--text-main);">اسم الخطر</label>
                        <input type="text" name="title" class="form-control" maxlength="300"
                               placeholder="مثال: تراكم أول أكسيد الكربون من عوادم المركبات في مواقف القبو"
                               value="{{ old('title', '') }}">
                        <small style="color:var(--text-muted);">إن تُرك فارغاً يُؤخذ من نوع الخطر (المستوى الثالث)</small>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" style="color:var(--text-main);">الوصف</label>
                        <textarea name="description" class="form-control" rows="4"
                                  placeholder="المصدر: … · التعرض: … · الحدث المحتمل: … · الموقع: …">{{ old('description', '') }}</textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">قناة الاتصال</label>
                        <input type="text" name="contact_channel" class="form-control" maxlength="200"
                               placeholder="مثال: مركز السلامة (المناوب) — مسؤول السلامة"
                               value="{{ old('contact_channel', '') }}">
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
                                <option value="{{$i}}" @selected(old('severity')==$i)>{{$i}} — {{ ['','طفيف','بسيط','متوسط','كبير','كارثي'][$i] }}</option>
                            @endfor
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">الاحتمالية <span class="text-danger">*</span></label>
                        <select name="likelihood" id="likSelect" class="form-select" required onchange="updateScore()">
                            @for($i=1;$i<=5;$i++)
                                <option value="{{$i}}" @selected(old('likelihood')==$i)>{{$i}} — {{ ['','نادر','غير مرجح','ممكن','مرجح','شبه مؤكد'][$i] }}</option>
                            @endfor
                        </select>
                    </div>
                    <div class="col-md-4 text-center">
                        <label class="form-label" style="color:var(--text-main);">درجة الخطر</label>
                        <div><span id="scoreBadge" class="badge bg-secondary fs-5 px-3 py-2">1</span></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="color:var(--text-main);"><i class="bi bi-book-half me-1"></i>المرجع القانوني</label>
                        <input type="text" name="legal_reference" class="form-control"
                               placeholder="مثال: ISO 45001:2018 §6.1.2 — نظام العمل المادة 121"
                               value="{{ old('legal_reference') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="color:var(--text-main);">الفائدة من التوثيق</label>
                        <input type="text" name="benefit" class="form-control" value="{{ old('benefit') }}">
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
                            <option value="general" @selected(old('scope_type','general')==='general')>عام — المؤسسة بالكامل</option>
                            <option value="org_unit" @selected(old('scope_type')==='org_unit')>وحدة تنظيمية محددة</option>
                        </select>
                    </div>
                    <div class="col-md-4" id="orgUnitDiv" style="display:{{ old('scope_type')==='org_unit'?'block':'none' }};">
                        <label class="form-label" style="color:var(--text-main);">الوحدة التنظيمية</label>
                        <select name="organization_unit_id" class="form-select">
                            <option value="">اختر...</option>
                            @foreach($orgUnits as $unit)
                                <option value="{{ $unit->id }}" @selected(old('organization_unit_id')==$unit->id)>{{ $unit->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">المكان (المعهد)</label>
                        <select name="place_id" class="form-select">
                            <option value="">—</option>
                            @foreach($places as $place)
                                <option value="{{ $place->id }}" @selected(old('place_id', null) == $place->id)>{{ $place->code }} — {{ $place->name }}</option>
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
                                <div class="input-group input-group-sm mb-2">
                                    <input type="text" name="phases[{{ $phaseKey }}][cause_names][]" class="form-control" placeholder="اسم السبب...">
                                    <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()"><i class="bi bi-trash"></i></button>
                                </div>
                            </div>
                        </div>

                        {{-- المتأثرون --}}
                        <div class="mb-3">
                            <label class="form-label" style="color:var(--text-main);"><i class="bi bi-diagram-3 me-1"></i>العواقب والأضرار <small style="color:var(--text-muted);font-weight:400;">— انقر الدائرة واختر من يتضرر وبماذا</small></label>
                            @foreach(\App\Modules\Risk\Models\AffectedGroup::CIRCLES + ['أخرى' => []] as $circle => $circleNames)
                                @php $circleGroups = $circle === 'أخرى' ? $masterAndTenantGroups->filter(fn ($g) => \App\Modules\Risk\Models\AffectedGroup::circleOf($g->name) === 'أخرى') : $masterAndTenantGroups->whereIn('name', $circleNames); @endphp
                                @if($circleGroups->isNotEmpty())
                                <details class="ag-circle mb-2 rounded" style="border:1px solid var(--border-color);">
                                    <summary class="px-2 py-1 fw-bold" style="cursor:pointer;color:var(--text-main);">{{ $circle }} <span class="badge bg-success ag-count ms-1"></span> <small style="color:var(--text-muted);font-weight:400;">({{ $circleGroups->count() }})</small></summary>
                                    <div class="px-2 pb-1">
                                    @foreach($circleGroups as $group)
                                @php $gid = $group->id; @endphp
                                <div class="d-flex align-items-center gap-2 p-2 mb-2 rounded" style="background:var(--bg-main);">
                                    <input class="form-check-input" type="checkbox"
                                           name="phases[{{ $phaseKey }}][affected_group_ids][]"
                                           value="{{ $gid }}" id="rag_{{ $phaseKey }}_{{ $gid }}">
                                    <label for="rag_{{ $phaseKey }}_{{ $gid }}" style="color:var(--text-main);min-width:120px;font-weight:600;">{{ $group->name }}</label>
                                    <select name="phases[{{ $phaseKey }}][affected_impact][{{ $gid }}]" class="form-select form-select-sm" style="max-width:130px;">
                                        <option value="low">منخفض</option>
                                        <option value="medium" selected>متوسط</option>
                                        <option value="high">عالي</option>
                                        <option value="critical">حرج</option>
                                    </select>
                                    <select name="phases[{{ $phaseKey }}][affected_rep_scope][{{ $gid }}]" class="form-select form-select-sm" style="max-width:130px;">
                                        <option value="">— النطاق —</option>
                                        <option value="local">محلي</option>
                                        <option value="regional">إقليمي</option>
                                        <option value="national">وطني</option>
                                        <option value="international">دولي</option>
                                    </select>
                                    <input type="text" name="phases[{{ $phaseKey }}][affected_detail][{{ $gid }}]" class="form-control form-control-sm"
                                           placeholder="ما الضرر تحديداً؟" maxlength="1000" value="{{ old("phases.{$phaseKey}.affected_detail.{$gid}", '') }}">
                                </div>
                                    @endforeach
                                    </div>
                                </details>
                                @endif
                            @endforeach
                        </div>

                        {{-- الإجراءات --}}
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" style="color:var(--text-main);">الإجراء الوقائي</label>
                                <textarea name="phases[{{ $phaseKey }}][preventive_action]" class="form-control" rows="3">{{ old("phases.{$phaseKey}.preventive_action") }}</textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="color:var(--text-main);">الإجراء التصحيحي</label>
                                <textarea name="phases[{{ $phaseKey }}][corrective_action]" class="form-control" rows="3">{{ old("phases.{$phaseKey}.corrective_action") }}</textarea>
                            </div>
                        </div>

                        {{-- التقييم المتبقي --}}
                        <div class="mb-3">
                            <label class="form-label" style="color:var(--text-main);"><i class="bi bi-clipboard2-check me-1"></i>التقييم بعد الإجراءات (الخطر المتبقي)</label>
                            <textarea name="phases[{{ $phaseKey }}][residual_assessment]" class="form-control" rows="2"
                                      placeholder="ما مستوى الخطر المتبقي بعد تطبيق الإجراءات في مرحلة {{ $meta['label'] }}؟">{{ old("phases.{$phaseKey}.residual_assessment") }}</textarea>
                        </div>

                        {{-- الجهة والشخص --}}
                        <div class="row g-3 p-2 rounded" style="background:var(--bg-main);">
                            <div class="col-12 mb-1"><strong style="color:var(--text-main);font-size:0.85rem;"><i class="bi bi-person-badge me-1"></i>الجهة والشخص المسؤول</strong></div>
                            <div class="col-md-6">
                                <label class="form-label small" style="color:var(--text-muted);">الجهة (من الهيكل)</label>
                                <select name="phases[{{ $phaseKey }}][responsible_org_unit_id]" class="form-select form-select-sm">
                                    <option value="">— اختر —</option>
                                    @foreach($orgUnits as $unit)
                                        <option value="{{ $unit->id }}" @selected(old("phases.{$phaseKey}.responsible_org_unit_id")==$unit->id)>{{ $unit->name }}</option>
                                    @endforeach
                                </select>
                                <input type="text" name="phases[{{ $phaseKey }}][responsible_org_unit_text]"
                                       class="form-control form-control-sm mt-1" placeholder="أو اكتب نصاً..."
                                       value="{{ old("phases.{$phaseKey}.responsible_org_unit_text") }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small" style="color:var(--text-muted);">الشخص المسؤول</label>
                                <select name="phases[{{ $phaseKey }}][responsible_user_id]" class="form-select form-select-sm">
                                    <option value="">— اختر —</option>
                                    @foreach($tenantUsers as $u)
                                        <option value="{{ $u->id }}" @selected(old("phases.{$phaseKey}.responsible_user_id")==$u->id)>{{ $u->name }}</option>
                                    @endforeach
                                </select>
                                <input type="text" name="phases[{{ $phaseKey }}][responsible_user_text]"
                                       class="form-control form-control-sm mt-1" placeholder="أو اكتب اسماً..."
                                       value="{{ old("phases.{$phaseKey}.responsible_user_text") }}">
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-lg px-5"><i class="bi bi-check-lg me-2"></i>حفظ في السجل المرجعي</button>
            <a href="{{ route('risk.reference.index') }}" class="btn btn-outline-secondary btn-lg px-4">إلغاء</a>
        </div>
    </form>
</div>

{{-- العواقب والأضرار: عدّاد المختار في كل دائرة --}}
<script>
document.querySelectorAll('details.ag-circle').forEach(function (d) {
    var upd = function () { var n = d.querySelectorAll('input[type=checkbox]:checked').length; var b = d.querySelector('.ag-count'); if (b) b.textContent = n ? n : ''; };
    d.addEventListener('change', upd); upd();
});
</script>
@endsection

@push('scripts')
<script>
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
    sub.innerHTML = '<option>جاري التحميل...</option>';
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
            if (selSubId) loadRiskTypes(selSubId);
        }).catch(() => sub.innerHTML = '<option value="">غير متاح</option>');
}

function loadRiskTypes(subCatId, selTypeId = null) {
    const sel = document.getElementById('riskTypeSelect');
    if (!subCatId) { sel.innerHTML = '<option value="">اختر الفئة الفرعية أولاً...</option>'; return; }
    sel.innerHTML = '<option>جاري التحميل...</option>';
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

document.getElementById('subCatSelect').addEventListener('change', function() {
    loadRiskTypes(this.value);
});

function addCauseRow(phaseKey) {
    document.getElementById('causes-' + phaseKey).insertAdjacentHTML('beforeend', `
        <div class="input-group input-group-sm mb-2">
            <input type="text" name="phases[${phaseKey}][cause_names][]" class="form-control" placeholder="اسم السبب...">
            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()"><i class="bi bi-trash"></i></button>
        </div>`);
}

// Restore selections after validation error
const oldCatId = @json(old('category_id'));
const oldSubId  = @json(old('sub_category_id'));
const oldTypeId = @json(old('risk_type_category_id'));
if (oldCatId) loadSubCats(oldCatId, oldSubId);
if (oldSubId && oldTypeId) setTimeout(() => loadRiskTypes(oldSubId, oldTypeId), 600);

updateScore();
</script>
@endpush

@include('modules.risks.partials._taxonomy_modals')
