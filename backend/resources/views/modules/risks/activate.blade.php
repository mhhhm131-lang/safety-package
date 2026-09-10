@extends('layouts.app')

@section('page_title', 'تفعيل خطر — السجل الخاص')

@php
    use App\Modules\Risk\Models\RiskPhase;

    $phaseMeta = [
        RiskPhase::PHASE_PROACTIVE   => ['label' => 'استباقي',  'hint' => 'قبل بدء العمل — الوقاية والتحضير', 'icon' => 'bi-shield-plus',      'color' => '#3b82f6'],
        RiskPhase::PHASE_OPERATIONAL => ['label' => 'تشغيلي',  'hint' => 'أثناء العمل — الضوابط والرقابة',    'icon' => 'bi-lightning-charge', 'color' => '#f59e0b'],
        RiskPhase::PHASE_RESPONSE    => ['label' => 'استجابة', 'hint' => 'بعد وقوع الحادث — التعامل والتحقيق',  'icon' => 'bi-bandaid',          'color' => '#ef4444'],
    ];

    $phasesByKey = $risk->phases->keyBy('phase');
@endphp

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1" style="color: var(--text-main);"><i class="bi bi-lightning me-2" style="color:#e74c3c;"></i>تفعيل خطر في السجل الخاص</h4>
            <small style="color: var(--text-muted);">ينسخ مراحل الخطر الثلاث من السجل العام — عدّلها حسب سياق التفعيل</small>
        </div>
        <a href="{{ route('risk.reference.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-right me-1"></i> رجوع</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- الخطر المرجعي (للقراءة فقط) --}}
    <div class="card mb-3" style="background:rgba(8,145,178,0.05);border:2px solid var(--accent);">
        <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
            <h6 class="mb-0" style="color:var(--accent);"><i class="bi bi-bookmark-check me-2"></i>الخطر المرجعي (السجل العام)</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <h5 style="color:var(--text-main);">{{ $risk->title }}</h5>
                    <p class="mb-0" style="color:var(--text-muted);">{{ $risk->description }}</p>
                </div>
                <div class="col-md-4 text-center">
                    <div class="d-flex gap-3 justify-content-center">
                        <div><small style="color:var(--text-muted);">الفئة</small><br><span class="badge bg-info">{{ $risk->category->name ?? '-' }}</span></div>
                        <div><small style="color:var(--text-muted);">الدرجة</small><br><x-risk-score-badge :score="(int)($risk->risk_score ?? 0)" /></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('risk.activate', $risk) }}">
        @csrf

        {{-- 1. النطاق --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-geo-alt me-2"></i>النطاق</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" style="color:var(--text-main);">نوع النطاق <span class="text-danger">*</span></label>
                        <select name="scope_type" id="scopeType" class="form-select" required onchange="document.getElementById('orgUnitDiv').style.display=this.value==='org_unit'?'block':'none'">
                            <option value="general" @selected(old('scope_type') === 'general')>عام — المؤسسة بالكامل</option>
                            <option value="org_unit" @selected(old('scope_type') === 'org_unit')>وحدة تنظيمية محددة</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="orgUnitDiv" style="display:{{ old('scope_type') === 'org_unit' ? 'block' : 'none' }};">
                        <label class="form-label" style="color:var(--text-main);">الوحدة التنظيمية</label>
                        <select name="organization_unit_id" class="form-select">
                            <option value="">اختر...</option>
                            @foreach($orgUnits as $unit)
                                <option value="{{ $unit->id }}" @selected(old('organization_unit_id') == $unit->id)>{{ $unit->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
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

        {{-- 1-ب. تعريف الخطر (قرار ٢١) --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-card-text me-2"></i>تعريف الخطر</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" style="color:var(--text-main);">اسم الخطر</label>
                        <input type="text" name="title" class="form-control" maxlength="300" value="{{ old('title', $risk->title) }}">
                        <small style="color:var(--text-muted);">إن تُرك فارغاً يُؤخذ من نوع الخطر (المستوى الثالث)</small>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" style="color:var(--text-main);">الوصف</label>
                        <textarea name="description" class="form-control" rows="4">{{ old('description', $risk->description) }}</textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">قناة الاتصال</label>
                        <input type="text" name="contact_channel" class="form-control" maxlength="200" value="{{ old('contact_channel', $risk->contact_channel) }}">
                    </div>
                </div>
            </div>
        </div>

        {{-- 2. التقييم (قابل للتعديل) --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-speedometer me-2"></i>التقييم</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">الخطورة <span class="text-danger">*</span></label>
                        <select name="severity" id="sev" class="form-select" required onchange="updateScore()">
                            @for($i=1;$i<=5;$i++)<option value="{{$i}}" @selected(old('severity', $risk->severity) == $i)>{{$i}} — {{ ['','طفيف','بسيط','متوسط','كبير','كارثي'][$i] }}</option>@endfor
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">الاحتمالية <span class="text-danger">*</span></label>
                        <select name="likelihood" id="lik" class="form-select" required onchange="updateScore()">
                            @for($i=1;$i<=5;$i++)<option value="{{$i}}" @selected(old('likelihood', $risk->likelihood) == $i)>{{$i}} — {{ ['','نادر','غير مرجح','ممكن','مرجح','شبه مؤكد'][$i] }}</option>@endfor
                        </select>
                    </div>
                    <div class="col-md-4 text-center">
                        <label class="form-label" style="color:var(--text-main);">درجة الخطر</label>
                        <div><span id="scoreBadge" class="badge fs-4 px-4 py-2 bg-warning">{{ $risk->risk_score }}</span></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" style="color:var(--text-main);"><i class="bi bi-book-half me-1"></i>المرجع القانوني</label>
                        <input type="text" name="legal_reference" class="form-control"
                               placeholder="مثال: ISO 45001:2018 §6.1.2 — نظام العمل المادة 121"
                               value="{{ old('legal_reference', $risk->legal_reference) }}">
                        <small style="color:var(--text-muted);">موروث من السجل المرجعي — عدّله إن كان النطاق يستدعي مرجعاً مختلفاً</small>
                    </div>
                </div>
            </div>
        </div>

        {{-- ═══════ المراحل الثلاث (مُنسوخة من المرجعي، قابلة للتعديل) ═══════ --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-layers me-2"></i>مراحل الخطر عند التفعيل</h6>
                <small style="color:var(--text-muted);">البيانات منسوخة من السجل المرجعي — عدّل ما يناسب سياق هذا التفعيل.</small>
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
                                <small style="color:var(--text-muted);">الخطر المتبقي (Residual Risk) — هل الإجراءات كافية؟</small>
                            </div>

                            <div class="row g-3 mb-3 p-2 rounded" style="background:var(--bg-main);">
                                <div class="col-12 mb-1"><strong style="color:var(--text-main);font-size:0.85rem;"><i class="bi bi-person-badge me-1"></i>المسؤولية</strong></div>

                                <div class="col-md-6">
                                    <label class="form-label small" style="color:var(--text-muted);">الإدارة من الهيكل</label>
                                    <select name="phases[{{ $phaseKey }}][responsible_org_unit_id]" class="form-select form-select-sm">
                                        <option value="">— اختر —</option>
                                        @foreach($orgUnits as $unit)
                                            <option value="{{ $unit->id }}" @selected(old("phases.{$phaseKey}.responsible_org_unit_id", $phase?->responsible_org_unit_id) == $unit->id)>
                                                {{ $unit->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <input type="text" name="phases[{{ $phaseKey }}][responsible_org_unit_text]"
                                           class="form-control form-control-sm mt-1"
                                           placeholder="أو نص حر..."
                                           value="{{ old("phases.{$phaseKey}.responsible_org_unit_text", $phase?->responsible_org_unit_text) }}">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small" style="color:var(--text-muted);">الشخص من المستخدمين</label>
                                    <select name="phases[{{ $phaseKey }}][responsible_user_id]" class="form-select form-select-sm">
                                        <option value="">— اختر —</option>
                                        @foreach($tenantUsers as $u)
                                            <option value="{{ $u->id }}" @selected(old("phases.{$phaseKey}.responsible_user_id", $phase?->responsible_user_id) == $u->id)>
                                                {{ $u->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <input type="text" name="phases[{{ $phaseKey }}][responsible_user_text]"
                                           class="form-control form-control-sm mt-1"
                                           placeholder="أو نص حر..."
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

                            <div>
                                <label class="form-label" style="color:var(--text-main);"><i class="bi bi-diagram-3 me-1"></i>العواقب والأضرار <small style="color:var(--text-muted);font-weight:400;">— انقر الدائرة واختر من يتضرر وبماذا</small></label>
                                @foreach(\App\Modules\Risk\Models\AffectedGroup::CIRCLES + ['أخرى' => []] as $circle => $circleNames)
                                    @php $circleGroups = $circle === 'أخرى' ? $masterAndTenantGroups->filter(fn ($g) => \App\Modules\Risk\Models\AffectedGroup::circleOf($g->name) === 'أخرى') : $masterAndTenantGroups->whereIn('name', $circleNames); @endphp
                                    @if($circleGroups->isNotEmpty())
                                    <details class="ag-circle mb-2 rounded" style="border:1px solid var(--border-color);" @if(collect($phaseGroupIds)->intersect($circleGroups->pluck('id'))->isNotEmpty()) open @endif>
                                        <summary class="px-2 py-1 fw-bold" style="cursor:pointer;color:var(--text-main);">{{ $circle }} <span class="badge bg-success ag-count ms-1"></span> <small style="color:var(--text-muted);font-weight:400;">({{ $circleGroups->count() }})</small></summary>
                                        <div class="px-2 pb-1">
                                        @foreach($circleGroups as $group)
                                    @php
                                        $gid = $group->id;
                                        $isChecked = in_array($gid, $phaseGroupIds, true);
                                        $detail = $phaseDetails->get($gid);
                                        $impact = $detail->impact ?? 'medium';
                                        $repScope = $detail->rep_scope ?? '';
                                        $gDetail  = $detail->impact_description ?? '';
                                    @endphp
                                    <div class="d-flex align-items-center gap-2 p-2 mb-2 rounded" style="background:var(--bg-main);">
                                        <input class="form-check-input" type="checkbox"
                                               name="phases[{{ $phaseKey }}][affected_group_ids][]"
                                               value="{{ $gid }}" @checked($isChecked)
                                               id="aag_{{ $phaseKey }}_{{ $gid }}">
                                        <label for="aag_{{ $phaseKey }}_{{ $gid }}" style="color:var(--text-main);min-width:120px;font-weight:600;">{{ $group->name }}</label>
                                        <select name="phases[{{ $phaseKey }}][affected_impact][{{ $gid }}]" class="form-select form-select-sm" style="max-width:130px;">
                                            <option value="1" @selected(\App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail::normalizeImpact($impact) === '1')>1 — طفيف</option>
                                            <option value="2" @selected(\App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail::normalizeImpact($impact) === '2')>2 — بسيط</option>
                                            <option value="3" @selected(\App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail::normalizeImpact($impact) === '3')>3 — متوسط</option>
                                            <option value="4" @selected(\App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail::normalizeImpact($impact) === '4')>4 — كبير</option>
                                            <option value="5" @selected(\App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail::normalizeImpact($impact) === '5')>5 — كارثي</option>
                                        </select>
                                        @if($group->name === 'السمعة'){{-- قرار ٢٦: النطاق للسمعة وحدها --}}
                                        <select name="phases[{{ $phaseKey }}][affected_rep_scope][{{ $gid }}]" class="form-select form-select-sm" style="max-width:130px;">
                                            <option value="" @selected($repScope === '')>— النطاق —</option>
                                            <option value="local" @selected($repScope === 'local')>محلي</option>
                                            <option value="regional" @selected($repScope === 'regional')>إقليمي</option>
                                            <option value="national" @selected($repScope === 'national')>وطني</option>
                                            <option value="international" @selected($repScope === 'international')>دولي</option>
                                        </select>
                                        @endif
                                    <input type="text" name="phases[{{ $phaseKey }}][affected_detail][{{ $gid }}]" class="form-control form-control-sm"
                                               placeholder="ما الضرر تحديداً؟" maxlength="1000" value="{{ old("phases.{$phaseKey}.affected_detail.{$gid}", $gDetail) }}">
                                    </div>
                                        @endforeach
                                        </div>
                                    </details>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- التعيينات --}}
        <div class="card mb-3" style="background:var(--bg-card);border:1px solid var(--border-color);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);">
                <h6 class="mb-0 fw-bold" style="color:var(--accent);"><i class="bi bi-person-check me-2"></i>التعيينات والموعد المستهدف</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">منسق السلامة المعيّن</label>
                        <select name="assigned_coordinator_id" class="form-select">
                            <option value="">— اختر —</option>
                            @foreach($tenantUsers as $u)
                                <option value="{{ $u->id }}" @selected(old('assigned_coordinator_id') == $u->id)>{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">فريق التنفيذ المعيّن</label>
                        <select name="assigned_field_team_id" class="form-select">
                            <option value="">— اختر —</option>
                            @foreach($tenantUsers as $u)
                                <option value="{{ $u->id }}" @selected(old('assigned_field_team_id') == $u->id)>{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="color:var(--text-main);">تاريخ الإغلاق المستهدف</label>
                        <input type="date" name="target_closure_date" class="form-control" value="{{ old('target_closure_date') }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label" style="color:var(--text-main);">ملاحظات التفعيل</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="ملاحظات حول سياق التفعيل...">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-danger btn-lg px-5"><i class="bi bi-lightning-fill me-2"></i>تفعيل في السجل الخاص</button>
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
    const s = parseInt(document.getElementById('sev').value) || 1;
    const l = parseInt(document.getElementById('lik').value) || 1;
    const score = s * l;
    const badge = document.getElementById('scoreBadge');
    badge.textContent = score;
    badge.className = 'badge fs-4 px-4 py-2 ' + (score >= 15 ? 'bg-danger' : score >= 8 ? 'bg-warning' : 'bg-success');
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

updateScore();
</script>
@endpush
