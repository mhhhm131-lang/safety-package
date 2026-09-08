@extends('layouts.app')

@section('page_title', 'إضافة خطر في كتاب المخاطر')

@php
    use App\Modules\Risk\Models\RiskPhase;

    $phases = [
        RiskPhase::PHASE_PROACTIVE   => ['label' => 'استباقي',  'hint' => 'قبل بدء العمل — الوقاية والتحضير', 'icon' => 'bi-shield-plus',        'color' => '#3b82f6'],
        RiskPhase::PHASE_OPERATIONAL => ['label' => 'تشغيلي',  'hint' => 'أثناء العمل — الضوابط والرقابة',    'icon' => 'bi-lightning-charge',   'color' => '#f59e0b'],
        RiskPhase::PHASE_RESPONSE    => ['label' => 'استجابة', 'hint' => 'بعد وقوع الحادث — التعامل والتحقيق',  'icon' => 'bi-bandaid',            'color' => '#ef4444'],
    ];
@endphp

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1" style="color: var(--text-main);"><i class="bi bi-shield-plus me-2" style="color:var(--accent);"></i>إضافة خطر في كتاب المخاطر</h4>
            <small style="color: var(--text-muted);">الحقول العامة مشتركة، ثم لكل خطر ثلاث مراحل: استباقي / تشغيلي / استجابة</small>
        </div>
        <a href="{{ route('risk.master.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-right me-1"></i> رجوع</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('risk.master.store') }}">
        @csrf

        {{-- ═══════════════════════════════════════════════════════════
             البيانات العامة — مشتركة لكل المراحل
             ═══════════════════════════════════════════════════════════ --}}

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
                                <option value="{{ $cat->id }}" @selected(old('category_id') == $cat->id)>{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">الفئة الفرعية</label>
                        <select name="sub_category_id" id="subCategorySelect" class="form-select" disabled>
                            <option value="">اختر الفئة أولاً...</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">درجة الخطر</label>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <span id="riskScoreBadge" class="badge bg-secondary fs-5 px-3 py-2">0</span>
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
                        <input type="text" name="title" class="form-control form-control-lg" required placeholder="مثال: سقوط عامل من سقالة أثناء العمل على ارتفاع" value="{{ old('title') }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label" style="color:var(--text-main);">وصف الخطر <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control" rows="3" required placeholder="وصف تفصيلي للخطر وظروف حدوثه...">{{ old('description') }}</textarea>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" style="color:var(--text-main);">الخطورة (1-5) <span class="text-danger">*</span></label>
                        <select name="severity" id="severitySelect" class="form-select" required onchange="updateScore()">
                            @for($i=1;$i<=5;$i++)<option value="{{$i}}" @selected(old('severity') == $i)>{{$i}} — {{ ['','طفيف','بسيط','متوسط','كبير','كارثي'][$i] }}</option>@endfor
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" style="color:var(--text-main);">الاحتمالية (1-5) <span class="text-danger">*</span></label>
                        <select name="likelihood" id="likelihoodSelect" class="form-select" required onchange="updateScore()">
                            @for($i=1;$i<=5;$i++)<option value="{{$i}}" @selected(old('likelihood') == $i)>{{$i}} — {{ ['','نادر','غير مرجح','ممكن','مرجح','شبه مؤكد'][$i] }}</option>@endfor
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="color:var(--text-main);">الفائدة من التوثيق</label>
                        <input type="text" name="benefit" class="form-control" placeholder="ما الفائدة من توثيق هذا الخطر؟" value="{{ old('benefit') }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label" style="color:var(--text-main);"><i class="bi bi-book-half me-1"></i>المرجع القانوني</label>
                        <input type="text" name="legal_reference" class="form-control"
                               placeholder="مثال: ISO 45001:2018 §6.1.2 — نظام العمل المادة 121"
                               value="{{ old('legal_reference') }}">
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
                            <option value="">— يُحمَّل بعد اختيار الفرعية —</option>
                        </select>
                        <small style="color:var(--text-muted);">أو أدخل اسم جديد:</small>
                        <input type="text" name="type_category_name" class="form-control form-control-sm mt-1" placeholder="مثال: سقوط من سلم" value="{{ old('type_category_name') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="color:var(--text-main);">قناة التواصل</label>
                        <input type="text" name="contact_channel" class="form-control" placeholder="مثال: البريد الإلكتروني / الهاتف / واتساب" value="{{ old('contact_channel') }}">
                    </div>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════════════════
             المراحل الثلاث (tabs)
             ═══════════════════════════════════════════════════════════ --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-layers me-2"></i>مراحل الخطر</h6>
                <small style="color:var(--text-muted);">كل مرحلة لها بياناتها الخاصة — الأسباب والمتأثرون والإجراءات والمسؤولون قد تختلف بين المراحل.</small>
            </div>
            <div class="card-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    @foreach($phases as $phaseKey => $phaseMeta)
                    <li class="nav-item" role="presentation">
                        <button class="nav-link @if($loop->first) active @endif" type="button"
                                data-bs-toggle="tab"
                                data-bs-target="#phase-{{ $phaseKey }}"
                                role="tab"
                                style="color: {{ $phaseMeta['color'] }}; font-weight: 600;">
                            <i class="bi {{ $phaseMeta['icon'] }} me-1"></i>{{ $phaseMeta['label'] }}
                        </button>
                    </li>
                    @endforeach
                </ul>

                <div class="tab-content">
                    @foreach($phases as $phaseKey => $phaseMeta)
                    <div class="tab-pane @if($loop->first) show active @endif" id="phase-{{ $phaseKey }}" role="tabpanel">
                        <div class="alert py-2 mb-3" style="background: {{ $phaseMeta['color'] }}15; border-inline-start: 3px solid {{ $phaseMeta['color'] }}; color: var(--text-main);">
                            <i class="bi {{ $phaseMeta['icon'] }} me-2"></i>{{ $phaseMeta['hint'] }}
                        </div>

                        {{-- الإجراءات --}}
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" style="color:var(--text-main);">الإجراء الوقائي</label>
                                <textarea name="phases[{{ $phaseKey }}][preventive_action]" class="form-control" rows="3"
                                          placeholder="ما الذي يجب فعله كوقاية في مرحلة {{ $phaseMeta['label'] }}؟">{{ old("phases.{$phaseKey}.preventive_action") }}</textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="color:var(--text-main);">الإجراء التصحيحي</label>
                                <textarea name="phases[{{ $phaseKey }}][corrective_action]" class="form-control" rows="3"
                                          placeholder="ما الذي يجب فعله كإصلاح في مرحلة {{ $phaseMeta['label'] }}؟">{{ old("phases.{$phaseKey}.corrective_action") }}</textarea>
                            </div>
                        </div>

                        {{-- التقييم بعد الاجراءات --}}
                        <div class="mb-3">
                            <label class="form-label" style="color:var(--text-main);"><i class="bi bi-clipboard2-check me-1"></i>التقييم بعد الإجراءات</label>
                            <textarea name="phases[{{ $phaseKey }}][residual_assessment]" class="form-control" rows="2"
                                      placeholder="ما مستوى الخطر المتبقي بعد تطبيق الإجراءات في مرحلة {{ $phaseMeta['label'] }}؟">{{ old("phases.{$phaseKey}.residual_assessment") }}</textarea>
                            <small style="color:var(--text-muted);">الخطر المتبقي (Residual Risk) — هل الإجراءات كافية؟ ما الذي لا يزال يحتاج متابعة؟</small>
                        </div>

                        {{-- المسؤولية (نص حر في كتاب المخاطر — اقتراح للمؤسسات) --}}
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" style="color:var(--text-main);">الإدارة المسؤولة <small style="color:var(--text-muted);">(اقتراح — المؤسسة ستربط بهيكلها)</small></label>
                                <input type="text" name="phases[{{ $phaseKey }}][responsible_org_unit_text]" class="form-control"
                                       placeholder="مثال: قسم السلامة" value="{{ old("phases.{$phaseKey}.responsible_org_unit_text") }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="color:var(--text-main);">الشخص المسؤول <small style="color:var(--text-muted);">(اقتراح)</small></label>
                                <input type="text" name="phases[{{ $phaseKey }}][responsible_user_text]" class="form-control"
                                       placeholder="مثال: مدير السلامة" value="{{ old("phases.{$phaseKey}.responsible_user_text") }}">
                            </div>
                        </div>

                        {{-- الأسباب (ديناميكية) --}}
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label mb-0" style="color:var(--text-main);"><i class="bi bi-exclamation-circle me-1"></i>الأسباب</label>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addCauseRow('{{ $phaseKey }}')">
                                    <i class="bi bi-plus-lg me-1"></i>إضافة سبب
                                </button>
                            </div>
                            <div id="causes-{{ $phaseKey }}" class="causes-container">
                                <div class="input-group input-group-sm mb-2">
                                    <input type="text" name="phases[{{ $phaseKey }}][cause_names][]" class="form-control"
                                           placeholder="اسم السبب في مرحلة {{ $phaseMeta['label'] }}...">
                                    <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        {{-- المجموعات المتأثرة --}}
                        <div>
                            <label class="form-label" style="color:var(--text-main);"><i class="bi bi-people me-1"></i>المجموعات المتأثرة في هذه المرحلة</label>
                            @foreach(\App\Modules\Risk\Models\AffectedGroup::all() as $group)
                                @php $gid = $group->id; @endphp
                                <div class="d-flex align-items-center gap-2 p-2 mb-2 rounded" style="background:var(--bg-main);">
                                    <input class="form-check-input" type="checkbox"
                                           name="phases[{{ $phaseKey }}][affected_group_ids][]"
                                           value="{{ $gid }}"
                                           id="mag_{{ $phaseKey }}_{{ $gid }}">
                                    <label for="mag_{{ $phaseKey }}_{{ $gid }}" style="color:var(--text-main);min-width:120px;font-weight:600;">{{ $group->name }}</label>
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
                                </div>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- حفظ --}}
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-lg px-5"><i class="bi bi-check-lg me-2"></i>حفظ الخطر</button>
            <a href="{{ route('risk.master.index') }}" class="btn btn-outline-secondary btn-lg px-4">إلغاء</a>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
function updateScore() {
    const s = parseInt(document.getElementById('severitySelect').value) || 1;
    const l = parseInt(document.getElementById('likelihoodSelect').value) || 1;
    const score = s * l;
    const badge = document.getElementById('riskScoreBadge');
    badge.textContent = score;
    badge.className = 'badge fs-5 px-3 py-2 ' + (score >= 15 ? 'bg-danger' : score >= 8 ? 'bg-warning' : 'bg-success');
}

// Sub-category AJAX
document.getElementById('categorySelect').onchange = function() {
    const sub = document.getElementById('subCategorySelect');
    if (!this.value) { sub.disabled = true; return; }
    fetch('/app/risk/ajax/subcategories?category_id=' + this.value)
        .then(r => r.json())
        .then(data => {
            sub.innerHTML = '<option value="">اختر الفرعية...</option>';
            data.forEach(s => sub.innerHTML += `<option value="${s.id}">${s.name}</option>`);
            sub.disabled = false;
        })
        .catch(() => { sub.innerHTML = '<option value="">غير متاح</option>'; sub.disabled = false; });
};

// Add a new cause row inside a specific phase's container
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

// Load risk type categories when sub-category changes
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
